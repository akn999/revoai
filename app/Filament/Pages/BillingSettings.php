<?php

namespace App\Filament\Pages;

use App\Platform\RevoSettings;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * @property-read Schema $form
 */
class BillingSettings extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Pricing and billing settings';

    protected static ?string $navigationLabel = 'Pricing settings';

    protected string $view = 'filament.pages.settings-form';

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $settings = app(RevoSettings::class);

        $this->form->fill([
            'price_product_content' => $settings->price('product_content'),
            'price_field_regeneration' => $settings->price('field_regeneration'),
            'price_image_edit' => $settings->price('image_edit'),
            'starter_credits' => $settings->starterCredits(),
            'low_balance_threshold' => $settings->lowBalanceThreshold(),
            'purchase_verifier' => $settings->purchaseVerifier(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Default prices (credits)')->description('Used when neither the preset nor the model sets its own price.')->columns(3)->components([
                TextInput::make('price_product_content')->label('Product content')->numeric()->integer()->minValue(0)->required(),
                TextInput::make('price_field_regeneration')->label('Field regeneration')->numeric()->integer()->minValue(0)->required(),
                TextInput::make('price_image_edit')->label('Image edit (per image)')->numeric()->integer()->minValue(0)->required(),
            ]),
            Section::make('Wallet')->columns(2)->components([
                TextInput::make('starter_credits')->label('Starter credits (once per store)')->numeric()->integer()->minValue(0)->required(),
                TextInput::make('low_balance_threshold')->label('Low-balance notice at')->numeric()->integer()->minValue(0)->required(),
            ]),
            Section::make('Purchase verification')->components([
                Select::make('purchase_verifier')->options(['client_result' => 'Trust the checkout result (interim)', 'salla_confirmed' => 'Salla confirmed (when Salla provides a webhook)'])->required()
                    ->helperText('Switching takes effect immediately, without a deploy.'),
            ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $settings = app(RevoSettings::class);

        foreach (['product_content', 'field_regeneration', 'image_edit'] as $action) {
            $settings->set("prices.{$action}", (int) $data["price_{$action}"]);
        }

        $settings->set('starter_credits', (int) $data['starter_credits']);
        $settings->set('low_balance_threshold', (int) $data['low_balance_threshold']);
        $settings->set('purchase_verifier', $data['purchase_verifier']);

        Notification::make()->title('Settings saved')->success()->send();
    }
}
