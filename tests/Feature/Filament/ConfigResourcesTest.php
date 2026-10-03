<?php

use App\Filament\Resources\AiModels\Pages\ManageAiModels;
use App\Filament\Resources\ContextOptions\Pages\ManageContextOptions;
use App\Filament\Resources\CreditPacks\Pages\ManageCreditPacks;
use App\Filament\Resources\ModerationCategories\Pages\ManageModerationCategories;
use App\Filament\Resources\Plans\Pages\ManagePlans;
use App\Filament\Resources\Presets\Pages\ManagePresets;
use App\Filament\Resources\PromptDefaults\Pages\ManagePromptDefaults;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\AiModel;
use App\Models\ContextOption;
use App\Models\CreditPack;
use App\Models\Merchant;
use App\Models\ModerationCategory;
use App\Models\Plan;
use App\Models\Preset;
use App\Models\PromptDefault;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->admin = AdminUser::factory()->withAppAuthentication()->create();
    $this->actingAs($this->admin, 'admin');
});

describe('access', function () {
    test('every config resource page renders for an admin', function (string $page) {
        Livewire::test($page)->assertSuccessful();
    })->with([ManageAiModels::class, ManagePresets::class, ManagePromptDefaults::class, ManageModerationCategories::class, ManageContextOptions::class, ManagePlans::class, ManageCreditPacks::class]);

    test('guests and web users are kept out of the panel', function () {
        auth('admin')->logout();
        auth()->forgetGuards();
        expect($this->get('/admin/ai-models')->status())->not->toBe(200);

        $this->actingAs(User::factory()->create());
        expect($this->get('/admin/ai-models')->status())->not->toBe(200);
    });
});

describe('AI models', function () {
    test('an admin can create a model with prices, features and a JSON schema', function () {
        Livewire::test(ManageAiModels::class)
            ->callAction('create', [
                'provider' => 'fal', 'provider_model_id' => 'fal/new', 'name_ar' => 'نموذج', 'name_en' => 'New model', 'features' => ['image_edit'],
                'default_for' => [], 'active' => true, 'sort' => 1, 'prices' => ['image_edit' => '25'], 'param_schema' => '{"type":"object","properties":{"seed":{"type":"integer"}}}',
            ])->assertHasNoFormErrors();

        $model = AiModel::where('provider_model_id', 'fal/new')->sole();
        expect($model->param_schema['properties']['seed']['type'])->toBe('integer')->and($model->features)->toBe(['image_edit'])->and($model->priceFor('image_edit'))->toBe(25);
    });

    test('an invalid parameter schema and missing fields are refused', function () {
        Livewire::test(ManageAiModels::class)
            ->callAction('create', ['provider' => 'fal', 'provider_model_id' => 'x', 'name_ar' => 'a', 'name_en' => 'b', 'param_schema' => '{not json'])
            ->assertHasFormErrors(['param_schema']);
        Livewire::test(ManageAiModels::class)->callAction('create', [])->assertHasFormErrors(['provider' => 'required', 'provider_model_id' => 'required']);
    });

    test('filters narrow the table by provider and active state', function () {
        $fal = AiModel::factory()->image()->create();
        $off = AiModel::factory()->inactive()->create();

        Livewire::test(ManageAiModels::class)->filterTable('provider', 'fal')->assertCanSeeTableRecords([$fal])->assertCanNotSeeTableRecords([$off])
            ->removeTableFilter('provider')->filterTable('active', false)->assertCanSeeTableRecords([$off])->assertCanNotSeeTableRecords([$fal]);
    });

    test('editing is written to the admin audit log with before and after', function () {
        $model = AiModel::factory()->create(['name_en' => 'Before']);

        Livewire::test(ManageAiModels::class)->callAction(TestAction::make('edit')->table($model), ['name_en' => 'After'])->assertHasNoFormErrors();

        $audit = AdminAuditLog::where('auditable_type', AiModel::class)->where('event', 'updated')->sole();
        expect($audit)->admin_user_id->toBe($this->admin->id)->auditable_id->toBe((string) $model->id)
            ->and($audit->old)->toBe(['name_en' => 'Before'])->and($audit->new)->toBe(['name_en' => 'After']);
    });

    test('models cannot be deleted from the panel', function () {
        $model = AiModel::factory()->create();

        Livewire::test(ManageAiModels::class)->assertTableActionDoesNotExist('delete', record: $model);
    });
});

describe('presets', function () {
    test('only default presets are listed and created ones have no store', function () {
        $mine = Preset::factory()->create(['merchant_id' => null, 'name_en' => 'Default']);
        $store = Preset::factory()->create(['merchant_id' => Merchant::factory()->create()->merchant_id, 'name_en' => 'Store']);

        Livewire::test(ManagePresets::class)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$store])
            ->callAction('create', ['name_ar' => 'ق', 'name_en' => 'Fresh', 'prompt' => 'p', 'active' => true])->assertHasNoFormErrors();

        expect(Preset::where('name_en', 'Fresh')->sole()->merchant_id)->toBeNull();
    });

    test('presets can be deleted and the deletion is audited', function () {
        $preset = Preset::factory()->create(['merchant_id' => null]);

        Livewire::test(ManagePresets::class)->callAction(TestAction::make('delete')->table($preset));

        expect(Preset::find($preset->id))->toBeNull()->and(AdminAuditLog::where('event', 'deleted')->where('auditable_type', Preset::class)->exists())->toBeTrue();
    });
});

describe('prompts', function () {
    test('default prompts can be edited but not created or deleted', function () {
        $prompt = PromptDefault::factory()->create(['key' => 'product_tone', 'body' => 'old']);

        Livewire::test(ManagePromptDefaults::class)->assertActionDoesNotExist('create')->assertTableActionDoesNotExist('delete', record: $prompt)
            ->callAction(TestAction::make('edit')->table($prompt), ['body' => 'new text'])->assertHasNoFormErrors();

        expect($prompt->fresh()->body)->toBe('new text')->and($prompt->fresh()->key)->toBe('product_tone');
    });

    test('an empty prompt is refused', function () {
        $prompt = PromptDefault::factory()->create();

        Livewire::test(ManagePromptDefaults::class)->callAction(TestAction::make('edit')->table($prompt), ['body' => ''])->assertHasFormErrors(['body' => 'required']);
    });
});

describe('moderation categories', function () {
    test('categories can be switched off from the table and are audited', function () {
        $category = ModerationCategory::factory()->create(['active' => true]);

        Livewire::test(ManageModerationCategories::class)->assertActionDoesNotExist('create')->assertTableActionDoesNotExist('delete', record: $category)
            ->callAction(TestAction::make('edit')->table($category), ['active' => false, 'name_ar' => $category->name_ar, 'name_en' => $category->name_en, 'instruction' => 'x']);

        expect($category->fresh()->active)->toBeFalse()->and(AdminAuditLog::where('auditable_type', ModerationCategory::class)->exists())->toBeTrue();
    });
});

describe('context options', function () {
    test('options can be created with both labels and are validated', function () {
        Livewire::test(ManageContextOptions::class)->callAction('create', ['field' => 'industry', 'key' => 'pets', 'label_ar' => 'حيوانات', 'label_en' => 'Pets', 'sort' => 5, 'active' => true])->assertHasNoFormErrors();
        Livewire::test(ManageContextOptions::class)->callAction('create', ['field' => 'industry', 'key' => 'bad key!', 'label_ar' => 'x', 'label_en' => 'x'])->assertHasFormErrors(['key']);

        expect(ContextOption::where('key', 'pets')->sole()->label_ar)->toBe('حيوانات');
    });

    test('they filter by field', function () {
        $a = ContextOption::factory()->create(['field' => 'industry']);
        $b = ContextOption::factory()->create(['field' => 'audience']);

        Livewire::test(ManageContextOptions::class)->filterTable('field', 'industry')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);
    });
});

describe('plans and packs', function () {
    test('a plan stores boolean feature flags from key-value input', function () {
        Livewire::test(ManagePlans::class)->callAction('create', ['slug' => 'plus', 'name' => 'Plus', 'salla_plan_name' => 'Plus Plan', 'is_active' => true, 'feature_flags' => ['product_content' => '1', 'chat' => '0']])->assertHasNoFormErrors();

        $plan = Plan::where('slug', 'plus')->sole();
        expect($plan->feature_flags)->toBe(['product_content' => true, 'chat' => false]);
    });

    test('credit packs need a unique add-on slug and positive credits', function () {
        CreditPack::factory()->create(['salla_addon_slug' => 'taken']);

        Livewire::test(ManageCreditPacks::class)->callAction('create', ['salla_addon_slug' => 'taken', 'credits' => 100, 'name_ar' => 'a', 'name_en' => 'b'])->assertHasFormErrors(['salla_addon_slug']);
        Livewire::test(ManageCreditPacks::class)->callAction('create', ['salla_addon_slug' => 'new', 'credits' => 0, 'name_ar' => 'a', 'name_en' => 'b'])->assertHasFormErrors(['credits']);
        Livewire::test(ManageCreditPacks::class)->callAction('create', ['salla_addon_slug' => 'new', 'credits' => 500, 'name_ar' => 'a', 'name_en' => 'b', 'active' => true])->assertHasNoFormErrors();

        expect(CreditPack::where('salla_addon_slug', 'new')->sole()->credits)->toBe(500);
    });
});

test('changes made outside the panel are not audited as admin changes', function () {
    auth('admin')->logout();

    AiModel::factory()->create();

    expect(AdminAuditLog::count())->toBe(0);
});
