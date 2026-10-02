<?php

use App\Enums\ActivityLevel;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Resources\ActivityLogs\Pages\ViewActivityLog;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\ActivityLog;
use App\Models\AppEvent;
use App\Models\Merchant;
use App\Models\Subscription;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-06-15 12:00:00');
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create());
    ActivityLog::query()->delete();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function logEntry(array $attributes = []): ActivityLog
{
    return ActivityLog::factory()->create(array_merge([
        'channel' => 'web',
        'action' => 'http.request',
        'level' => ActivityLevel::Info,
        'message' => 'entry',
        'context' => null,
        'created_at' => now()->subHour(),
    ], $attributes));
}

/**
 * @return array<string, ActivityLog>
 */
function logFixture(): array
{
    Merchant::factory()->create(['id' => 1, 'merchant_id' => 500, 'name' => 'Alpha Store']);
    Merchant::factory()->create(['id' => 2, 'merchant_id' => 1, 'name' => 'Beta Store']);

    return [
        'webInfo' => logEntry([
            'message' => 'GET /dashboard → 200', 'status_code' => 200, 'duration_ms' => 120, 'http_method' => 'GET',
            'url' => 'https://app.test/dashboard', 'ip_address' => '10.0.0.1', 'actor_type' => User::class, 'actor_id' => '7',
            'correlation_id' => 'corr-A', 'created_at' => now()->subHour(),
        ]),
        'webWarning' => logEntry([
            'level' => ActivityLevel::Warning, 'message' => 'GET /missing → 404', 'status_code' => 404, 'duration_ms' => 40,
            'http_method' => 'GET', 'url' => 'https://app.test/missing', 'ip_address' => '10.0.0.3', 'correlation_id' => 'corr-B',
            'created_at' => now()->subHours(2),
        ]),
        'webError' => logEntry([
            'level' => ActivityLevel::Error, 'message' => 'POST /pay → 500', 'status_code' => 500, 'duration_ms' => 900,
            'http_method' => 'POST', 'url' => 'https://app.test/pay', 'ip_address' => '10.0.0.2', 'correlation_id' => 'corr-C',
            'context' => ['exception' => ['class' => RuntimeException::class, 'message' => 'pay failed', 'file' => '/app/Pay.php:10', 'trace' => ['#0 a', '#1 b']]],
            'created_at' => now()->subHours(3),
        ]),
        'webhookReceived' => logEntry([
            'channel' => 'webhook', 'action' => 'salla.received', 'message' => 'Received app.installed', 'merchant_id' => 500,
            'actor_type' => 'system', 'subject_type' => AppEvent::class, 'subject_id' => '11', 'correlation_id' => 'corr-A',
            'created_at' => now()->subMinutes(30),
        ]),
        'webhookFailed' => logEntry([
            'channel' => 'webhook', 'action' => 'salla.failed', 'level' => ActivityLevel::Error, 'message' => 'Webhook processing failed permanently',
            'merchant_id' => 500, 'actor_type' => 'system', 'context' => ['exception' => ['class' => LogicException::class, 'message' => 'boom']],
            'created_at' => now()->subHours(5),
        ]),
        'modelUpdated' => logEntry([
            'channel' => 'model', 'action' => 'subscription.updated', 'message' => 'Subscription #3 updated', 'merchant_id' => 500,
            'subject_type' => Subscription::class, 'subject_id' => '3', 'correlation_id' => 'corr-A', 'created_at' => now()->subDays(3),
        ]),
        'outbound' => logEntry([
            'channel' => 'outbound', 'message' => 'POST https://accounts.salla.sa/oauth2/token → 200', 'status_code' => 200, 'duration_ms' => 500,
            'http_method' => 'POST', 'url' => 'https://accounts.salla.sa/oauth2/token', 'actor_type' => 'system', 'correlation_id' => 'corr-A',
            'created_at' => now()->subMinutes(45),
        ]),
        'userLogin' => logEntry([
            'channel' => 'user', 'action' => 'user.login', 'message' => 'User logged in', 'actor_type' => User::class, 'actor_id' => '7',
            'ip_address' => '10.0.0.1', 'created_at' => now()->subMinutes(10),
        ]),
        'system' => logEntry([
            'channel' => 'system', 'action' => 'exception.reported', 'level' => ActivityLevel::Critical, 'message' => 'Database down',
            'actor_type' => 'system', 'merchant_id' => 1, 'context' => ['note' => 'unique-context-marker', 'exception' => ['class' => PDOException::class, 'message' => 'down']],
            'created_at' => now()->subMinutes(20),
        ]),
        'logLine' => logEntry([
            'channel' => 'log', 'action' => 'log.debug', 'level' => ActivityLevel::Debug, 'message' => 'cache warmed', 'created_at' => now()->subMinute(),
        ]),
        'custom' => logEntry([
            'channel' => 'custom', 'action' => 'custom.thing', 'level' => ActivityLevel::Warning, 'message' => 'from a future channel',
            'created_at' => now()->subMinutes(15),
        ]),
        'orphanMerchant' => logEntry(['channel' => 'system', 'action' => 'orphan.entry', 'merchant_id' => 999, 'created_at' => now()->subMinutes(5)]),
    ];
}

/**
 * @param  array<string, ActivityLog>  $fixture
 * @param  array<int, string>  $keys
 * @return array<int, ActivityLog>
 */
function logsOf(array $fixture, array $keys): array
{
    return array_map(fn (string $key): ActivityLog => $fixture[$key], $keys);
}

/**
 * @param  array<string, ActivityLog>  $fixture
 * @param  array<int, string>  $keys
 * @return array<int, ActivityLog>
 */
function logsExcept(array $fixture, array $keys): array
{
    return array_values(array_diff_key($fixture, array_flip($keys)));
}

describe('authorization and read-only', function () {
    test('guests are sent to the panel login', function () {
        auth()->logout();

        $this->get('/admin/activity-logs')->assertRedirect('/admin/login');
    });

    test('a registered user can open the list and an entry', function () {
        $entry = logEntry();

        $this->get('/admin/activity-logs')->assertOk();
        $this->get('/admin/activity-logs/'.$entry->id)->assertOk();
    });

    test('there are no create or edit pages', function () {
        $entry = logEntry();

        $this->get('/admin/activity-logs/create')->assertNotFound();
        $this->get('/admin/activity-logs/'.$entry->id.'/edit')->assertNotFound();
        expect(array_keys(ActivityLogResource::getPages()))->toBe(['index', 'view']);
    });

    test('resource abilities deny every write', function () {
        $entry = logEntry();

        expect(ActivityLogResource::canViewAny())->toBeTrue()
            ->and(ActivityLogResource::canView($entry))->toBeTrue()
            ->and(ActivityLogResource::canCreate())->toBeFalse()
            ->and(ActivityLogResource::canEdit($entry))->toBeFalse()
            ->and(ActivityLogResource::canDelete($entry))->toBeFalse()
            ->and(ActivityLogResource::canDeleteAny())->toBeFalse();
    });

    test('the list offers only the view action', function () {
        $entry = logEntry();

        Livewire::test(ListActivityLogs::class)
            ->assertTableActionExists('view', record: $entry)
            ->assertTableActionDoesNotExist('edit', record: $entry)
            ->assertTableActionDoesNotExist('delete', record: $entry)
            ->assertActionDoesNotExist('create')
            ->assertTableBulkActionDoesNotExist('delete');
    });

    test('an entry can never be updated', function () {
        $entry = logEntry();

        expect(fn () => $entry->update(['message' => 'changed']))->toThrow(LogicException::class);
    });

    test('interacting with the list writes no entries', function () {
        $fixture = logFixture();
        $before = ActivityLog::count();

        Livewire::test(ListActivityLogs::class)
            ->set('activeTab', 'problems')
            ->filterTable('level', ['error'])
            ->searchTable('Webhook');
        Livewire::test(ViewActivityLog::class, ['record' => $fixture['webInfo']->id])->assertOk();

        expect(ActivityLog::count())->toBe($before);
    });
});

describe('default view and period', function () {
    test('the first load shows the last 24 hours only', function () {
        logFixture();
        $exact = logEntry(['action' => 'boundary.exact', 'created_at' => now()->subHours(24)]);
        $over = logEntry(['action' => 'boundary.over', 'created_at' => now()->subHours(24)->subSecond()]);

        Livewire::test(ListActivityLogs::class)
            ->assertCanSeeTableRecords([$exact])
            ->assertCanNotSeeTableRecords([$over]);
    });

    test('any time shows everything retained', function () {
        $fixture = logFixture();
        $over = logEntry(['created_at' => now()->subHours(24)->subSecond()]);

        Livewire::test(ListActivityLogs::class)
            ->filterTable('period', ['value' => 'all'])
            ->assertCanSeeTableRecords([$over, $fixture['modelUpdated']]);
    });

    test('each period includes its boundary and excludes one second older', function (string $value, int $seconds) {
        $on = logEntry(['action' => 'on.boundary', 'created_at' => now()->subSeconds($seconds)]);
        $past = logEntry(['action' => 'past.boundary', 'created_at' => now()->subSeconds($seconds + 1)]);

        Livewire::test(ListActivityLogs::class)
            ->filterTable('period', ['value' => $value])
            ->assertCanSeeTableRecords([$on])
            ->assertCanNotSeeTableRecords([$past]);
    })->with([
        '15 minutes' => ['15m', 900],
        '1 hour' => ['1h', 3600],
        '7 days' => ['7d', 604800],
    ]);

    test('resetting filters returns to 24 hours and removing them shows everything', function () {
        $old = logEntry(['created_at' => now()->subDays(3)]);
        $recent = logEntry(['created_at' => now()->subHour()]);

        Livewire::test(ListActivityLogs::class)
            ->filterTable('period', ['value' => 'all'])
            ->assertCanSeeTableRecords([$old, $recent])
            ->resetTableFilters()
            ->assertCanSeeTableRecords([$recent])
            ->assertCanNotSeeTableRecords([$old])
            ->removeTableFilters()
            ->assertCanSeeTableRecords([$old, $recent]);
    });

    test('a custom range includes whole minutes at both ends and combines with the period', function () {
        $atFrom = logEntry(['created_at' => '2026-06-15 10:00:00']);
        $beforeFrom = logEntry(['created_at' => '2026-06-15 09:59:59']);
        $endOfUntilMinute = logEntry(['created_at' => '2026-06-15 10:30:59']);
        $afterUntil = logEntry(['created_at' => '2026-06-15 10:31:00']);

        Livewire::test(ListActivityLogs::class)
            ->filterTable('created_range', ['from' => '2026-06-15 10:00', 'until' => '2026-06-15 10:30'])
            ->assertCanSeeTableRecords([$atFrom, $endOfUntilMinute])
            ->assertCanNotSeeTableRecords([$beforeFrom, $afterUntil]);

        $old = logEntry(['created_at' => now()->subDays(2)]);
        Livewire::test(ListActivityLogs::class)
            ->filterTable('created_range', ['from' => now()->subDays(3)->format('Y-m-d H:i')])
            ->assertCanNotSeeTableRecords([$old])
            ->filterTable('period', ['value' => 'all'])
            ->assertCanSeeTableRecords([$old]);
    });
});

describe('tabs', function () {
    test('all is the default tab and lists every channel', function () {
        $fixture = logFixture();

        Livewire::test(ListActivityLogs::class)
            ->assertSet('activeTab', 'all')
            ->assertCanSeeTableRecords(logsExcept($fixture, ['modelUpdated']));
    });

    test('problems shows warning and above only', function () {
        $fixture = logFixture();

        Livewire::test(ListActivityLogs::class)
            ->set('activeTab', 'problems')
            ->assertCanSeeTableRecords(logsOf($fixture, ['webWarning', 'webError', 'webhookFailed', 'system', 'custom']))
            ->assertCanNotSeeTableRecords(logsOf($fixture, ['webInfo', 'logLine', 'userLogin', 'outbound', 'webhookReceived']));
    });

    test('each channel tab shows only its own channel', function (string $tab, array $included) {
        $fixture = logFixture();

        Livewire::test(ListActivityLogs::class)
            ->filterTable('period', ['value' => 'all'])
            ->set('activeTab', $tab)
            ->assertCanSeeTableRecords(logsOf($fixture, $included))
            ->assertCanNotSeeTableRecords(logsExcept($fixture, $included));
    })->with([
        'web' => ['web', ['webInfo', 'webWarning', 'webError']],
        'webhook' => ['webhook', ['webhookReceived', 'webhookFailed']],
        'model' => ['model', ['modelUpdated']],
        'outbound' => ['outbound', ['outbound']],
        'user' => ['user', ['userLogin']],
        'system' => ['system', ['system', 'orphanMerchant']],
        'log' => ['log', ['logLine']],
    ]);

    test('an unregistered channel is reachable only through All and Problems', function () {
        $fixture = logFixture();

        Livewire::test(ListActivityLogs::class)->assertCanSeeTableRecords([$fixture['custom']]);
        Livewire::test(ListActivityLogs::class)->set('activeTab', 'problems')->assertCanSeeTableRecords([$fixture['custom']]);

        foreach (array_keys(ActivityLogResource::CHANNELS) as $channel) {
            Livewire::test(ListActivityLogs::class)->set('activeTab', $channel)->assertCanNotSeeTableRecords([$fixture['custom']]);
        }
    });

    test('a warning web entry is in both Web and Problems; an info one only in Web', function () {
        $fixture = logFixture();

        Livewire::test(ListActivityLogs::class)->set('activeTab', 'web')
            ->assertCanSeeTableRecords(logsOf($fixture, ['webWarning', 'webInfo']));
        Livewire::test(ListActivityLogs::class)->set('activeTab', 'problems')
            ->assertCanSeeTableRecords([$fixture['webWarning']])
            ->assertCanNotSeeTableRecords([$fixture['webInfo']]);
    });

    test('switching tabs keeps filters and the search term', function () {
        $fixture = logFixture();

        Livewire::test(ListActivityLogs::class)
            ->filterTable('http_method', ['POST'])
            ->searchTable('pay')
            ->set('activeTab', 'web')
            ->assertCanSeeTableRecords([$fixture['webError']])
            ->assertCanNotSeeTableRecords(logsOf($fixture, ['webInfo', 'webWarning']))
            ->set('activeTab', 'outbound')
            ->assertCanNotSeeTableRecords([$fixture['webError']]);
    });

    test('tabs carry no badges', function () {
        logFixture();
        $tabs = (new ListActivityLogs)->getTabs();

        expect($tabs)->toHaveCount(10);
        foreach ($tabs as $tab) {
            expect($tab->getBadge())->toBeNull();
        }
    });
});

describe('table configuration', function () {
    test('visible columns exist and hidden ones are toggleable', function () {
        $entry = logEntry();

        $list = Livewire::test(ListActivityLogs::class);
        foreach (['created_at', 'level', 'channel', 'action', 'message', 'status_code', 'duration_ms', 'actor_type', 'merchant.name'] as $column) {
            $list->assertTableColumnExists($column);
        }
        foreach (['id', 'http_method', 'url', 'ip_address', 'user_agent', 'correlation_id', 'subject_type', 'merchant_id', 'context'] as $column) {
            $list->assertTableColumnExists($column, fn (TextColumn $c): bool => $c->isToggleable() && $c->isToggledHiddenByDefault(), $entry);
        }
    });

    test('sortable columns are sortable and level is not', function () {
        $entry = logEntry();

        $list = Livewire::test(ListActivityLogs::class);
        foreach (['created_at', 'channel', 'action', 'status_code', 'duration_ms'] as $column) {
            $list->assertTableColumnExists($column, fn (TextColumn $c): bool => $c->isSortable(), $entry);
        }
        $list->assertTableColumnExists('level', fn (TextColumn $c): bool => ! $c->isSortable() && $c->isBadge(), $entry);
    });

    test('status colors follow the HTTP class', function (?int $status, string $color) {
        $entry = logEntry(['status_code' => $status]);

        Livewire::test(ListActivityLogs::class)->assertTableColumnExists('status_code', function (TextColumn $column) use ($color): bool {
            return $column->getColor($column->getState()) === $color;
        }, $entry);
    })->with([[200, 'success'], [302, 'info'], [404, 'warning'], [503, 'danger'], [null, 'gray']]);

    test('actor text follows the canonical labels', function () {
        $user = logEntry(['actor_type' => User::class, 'actor_id' => '7']);
        $system = logEntry(['actor_type' => 'system']);
        $merchant = logEntry(['actor_type' => 'salla_merchant', 'actor_id' => '777']);
        $guest = logEntry(['actor_type' => null]);

        Livewire::test(ListActivityLogs::class)
            ->assertTableColumnFormattedStateSet('actor_type', 'User #7', $user)
            ->assertTableColumnFormattedStateSet('actor_type', 'System', $system)
            ->assertTableColumnFormattedStateSet('actor_type', 'Salla merchant 777', $merchant)
            ->assertTableColumnFormattedStateSet('actor_type', 'Guest / none', $guest);
    });

    test('every documented filter exists', function (string $filter) {
        logEntry();

        Livewire::test(ListActivityLogs::class)->assertTableFilterExists($filter);
    })->with([
        'period', 'created_range', 'level', 'action', 'http_method', 'status_class', 'slower_than', 'actor_kind', 'actor_id',
        'merchant', 'merchant_id', 'subject_type', 'subject_id', 'has_exception', 'correlation', 'ip_address',
    ]);

    test('the default order is newest id first and the page size options are 25, 50, 100', function () {
        $fixture = logFixture();
        $expected = collect(logsExcept($fixture, ['modelUpdated']))->sortByDesc('id')->values()->all();

        Livewire::test(ListActivityLogs::class)
            ->assertCanSeeTableRecords($expected, inOrder: true)
            ->assertTableColumnExists('id');

        $table = Livewire::test(ListActivityLogs::class)->instance()->getTable();
        expect($table->getPaginationPageOptions())->toBe([25, 50, 100])
            ->and($table->getDefaultPaginationPageOption())->toBe(50);
    });

    test('the header explains retention', function () {
        logEntry();

        Livewire::test(ListActivityLogs::class)->assertSee('Entries are kept for 14 days');

        config(['activity-log.retention_days' => 0]);
        Livewire::test(ListActivityLogs::class)->assertSee('Entries are kept indefinitely');
    });
});

describe('search', function () {
    test('each searchable field finds exactly its own rows', function (string $term, array $expectedKeys) {
        $fixture = logFixture();
        $entry = logEntry(['message' => 'a lonely entry']);
        $everything = [...array_values($fixture), $entry];
        $expected = array_map(fn (string $key) => $fixture[$key], $expectedKeys);
        $others = array_values(array_filter($everything, fn ($record) => ! in_array($record, $expected, true) && $record !== $fixture['modelUpdated']));

        Livewire::test(ListActivityLogs::class)
            ->searchTable($term)
            ->assertCanSeeTableRecords($expected)
            ->assertCanNotSeeTableRecords($others);
    })->with([
        'message' => ['cache warmed', ['logLine']],
        'action' => ['salla.failed', ['webhookFailed']],
        'url' => ['app.test/pay', ['webError']],
        'ip address' => ['10.0.0.2', ['webError']],
        'correlation id' => ['corr-C', ['webError']],
        'actor id' => ['7', ['webInfo', 'userLogin']],
        'merchant name' => ['Alpha Store', ['webhookReceived', 'webhookFailed']],
        'merchant id' => ['999', ['orphanMerchant']],
        'text inside the JSON context' => ['unique-context-marker', ['system']],
        'system actor' => ['system', ['webhookReceived', 'webhookFailed', 'outbound', 'system']],
    ]);

    test('two words are ANDed', function () {
        $fixture = logFixture();

        Livewire::test(ListActivityLogs::class)
            ->searchTable('Received salla')
            ->assertCanSeeTableRecords([$fixture['webhookReceived']])
            ->assertCanNotSeeTableRecords([$fixture['webhookFailed']]);
    });

    test('a term that matches nothing shows no rows', function () {
        logFixture();

        Livewire::test(ListActivityLogs::class)->searchTable('zzz-nothing')->assertCountTableRecords(0);
    });
});

describe('filters', function () {
    test('level accepts several values', function () {
        $fixture = logFixture();

        Livewire::test(ListActivityLogs::class)
            ->filterTable('level', ['error', 'critical'])
            ->assertCanSeeTableRecords(logsOf($fixture, ['webError', 'webhookFailed', 'system']))
            ->assertCanNotSeeTableRecords(logsOf($fixture, ['webWarning', 'webInfo', 'custom']));
    });

    test('action contains matches literally, including wildcard characters', function () {
        $fixture = logFixture();
        $underscore = logEntry(['action' => 'weird_action']);
        $other = logEntry(['action' => 'weirdXaction']);

        Livewire::test(ListActivityLogs::class)
            ->filterTable('action', ['value' => 'salla.'])
            ->assertCanSeeTableRecords(logsOf($fixture, ['webhookReceived', 'webhookFailed']))
            ->assertCanNotSeeTableRecords(logsOf($fixture, ['webInfo']));

        Livewire::test(ListActivityLogs::class)
            ->filterTable('action', ['value' => 'weird_'])
            ->assertCanSeeTableRecords([$underscore])
            ->assertCanNotSeeTableRecords([$other]);
    });

    test('http method and status class', function () {
        $fixture = logFixture();
        $edges = [
            199 => logEntry(['status_code' => 199]), 200 => logEntry(['status_code' => 200, 'action' => 'e200']),
            299 => logEntry(['status_code' => 299]), 300 => logEntry(['status_code' => 300]),
        ];

        Livewire::test(ListActivityLogs::class)
            ->filterTable('http_method', ['POST'])
            ->assertCanSeeTableRecords(logsOf($fixture, ['webError', 'outbound']))
            ->assertCanNotSeeTableRecords([$fixture['webInfo']]);

        Livewire::test(ListActivityLogs::class)
            ->filterTable('status_class', ['2xx'])
            ->assertCanSeeTableRecords([$edges[200], $edges[299], $fixture['webInfo']])
            ->assertCanNotSeeTableRecords([$edges[199], $edges[300], $fixture['webWarning']]);

        Livewire::test(ListActivityLogs::class)
            ->filterTable('status_class', ['4xx', '5xx'])
            ->assertCanSeeTableRecords(logsOf($fixture, ['webWarning', 'webError']))
            ->assertCanNotSeeTableRecords([$fixture['webInfo'], $fixture['logLine']]);

        Livewire::test(ListActivityLogs::class)
            ->filterTable('status_class', ['none'])
            ->assertCanSeeTableRecords([$fixture['logLine'], $fixture['userLogin']])
            ->assertCanNotSeeTableRecords([$fixture['webInfo']]);
    });

    test('slower than is inclusive, applies zero and excludes missing durations', function () {
        $fixture = logFixture();
        $zero = logEntry(['duration_ms' => 0, 'action' => 'zero']);
        $edge499 = logEntry(['duration_ms' => 499, 'action' => 'e499']);

        Livewire::test(ListActivityLogs::class)
            ->filterTable('slower_than', ['value' => '500'])
            ->assertCanSeeTableRecords([$fixture['outbound'], $fixture['webError']])
            ->assertCanNotSeeTableRecords([$edge499, $fixture['webInfo'], $fixture['logLine']]);

        Livewire::test(ListActivityLogs::class)
            ->filterTable('slower_than', ['value' => '0'])
            ->assertCanSeeTableRecords([$zero, $fixture['webInfo']])
            ->assertCanNotSeeTableRecords([$fixture['logLine']]);
    });

    test('actor kind and actor id', function () {
        $fixture = logFixture();

        Livewire::test(ListActivityLogs::class)
            ->filterTable('actor_kind', ['user'])
            ->assertCanSeeTableRecords(logsOf($fixture, ['webInfo', 'userLogin']))
            ->assertCanNotSeeTableRecords(logsOf($fixture, ['webhookReceived', 'logLine']));

        Livewire::test(ListActivityLogs::class)
            ->filterTable('actor_kind', ['guest', 'system'])
            ->assertCanSeeTableRecords(logsOf($fixture, ['logLine', 'webhookReceived', 'outbound']))
            ->assertCanNotSeeTableRecords(logsOf($fixture, ['webInfo', 'userLogin']));

        Livewire::test(ListActivityLogs::class)
            ->filterTable('actor_id', ['value' => '7'])
            ->assertCanSeeTableRecords(logsOf($fixture, ['webInfo', 'userLogin']))
            ->assertCanNotSeeTableRecords(logsOf($fixture, ['webhookReceived']));
    });

    test('the merchant filter uses the Salla merchant id, not the internal id', function () {
        $fixture = logFixture();

        Livewire::test(ListActivityLogs::class)
            ->filterTable('merchant', [1])
            ->assertCanSeeTableRecords([$fixture['system']])
            ->assertCanNotSeeTableRecords(logsOf($fixture, ['webhookReceived', 'webhookFailed', 'orphanMerchant']));
    });

    test('the merchant id filter reaches ids that have no merchants row', function () {
        $fixture = logFixture();

        Livewire::test(ListActivityLogs::class)
            ->filterTable('merchant_id', ['value' => '999'])
            ->assertCanSeeTableRecords([$fixture['orphanMerchant']])
            ->assertCountTableRecords(1);
    });

    test('subject type and subject id', function () {
        $fixture = logFixture();

        Livewire::test(ListActivityLogs::class)
            ->filterTable('subject_type', [AppEvent::class])
            ->assertCanSeeTableRecords([$fixture['webhookReceived']])
            ->assertCountTableRecords(1);

        Livewire::test(ListActivityLogs::class)
            ->filterTable('period', ['value' => 'all'])
            ->filterTable('subject_id', ['value' => '3'])
            ->assertCanSeeTableRecords([$fixture['modelUpdated']])
            ->assertCountTableRecords(1);
    });

    test('has exception in all three states', function () {
        $fixture = logFixture();
        $withException = logsOf($fixture, ['webError', 'webhookFailed', 'system']);

        Livewire::test(ListActivityLogs::class)
            ->filterTable('has_exception', true)
            ->assertCanSeeTableRecords($withException)
            ->assertCanNotSeeTableRecords([$fixture['webInfo'], $fixture['logLine']]);

        Livewire::test(ListActivityLogs::class)
            ->filterTable('has_exception', false)
            ->assertCanSeeTableRecords([$fixture['webInfo'], $fixture['logLine']])
            ->assertCanNotSeeTableRecords($withException);
    });

    test('correlation id and ip address are exact matches', function () {
        $fixture = logFixture();

        Livewire::test(ListActivityLogs::class)
            ->filterTable('period', ['value' => 'all'])
            ->filterTable('correlation', ['value' => 'corr-A'])
            ->assertCanSeeTableRecords(logsOf($fixture, ['webInfo', 'webhookReceived', 'modelUpdated', 'outbound']))
            ->assertCountTableRecords(4);

        Livewire::test(ListActivityLogs::class)
            ->filterTable('ip_address', ['value' => '10.0.0.1'])
            ->assertCanSeeTableRecords(logsOf($fixture, ['webInfo', 'userLogin']))
            ->assertCanNotSeeTableRecords([$fixture['webError']])
            ->assertCountTableRecords(2);
    });

    test('tab, filter and search combine as an intersection', function () {
        $fixture = logFixture();

        Livewire::test(ListActivityLogs::class)
            ->set('activeTab', 'problems')
            ->filterTable('level', ['error'])
            ->searchTable('pay')
            ->assertCanSeeTableRecords([$fixture['webError']])
            ->assertCountTableRecords(1)
            ->filterTable('level', ['info'])
            ->assertCountTableRecords(0);
    });
});

describe('sorting', function () {
    test('duration sorts across entries that have one', function () {
        $fixture = logFixture();

        Livewire::test(ListActivityLogs::class)
            ->filterTable('status_class', ['2xx', '4xx', '5xx'])
            ->sortTable('duration_ms', 'desc')
            ->assertCanSeeTableRecords(logsOf($fixture, ['webError', 'outbound', 'webInfo', 'webWarning']), inOrder: true)
            ->sortTable('duration_ms', 'asc')
            ->assertCanSeeTableRecords(logsOf($fixture, ['webWarning', 'webInfo', 'outbound', 'webError']), inOrder: true);
    });

    test('when sorts by creation time', function () {
        $old = logEntry(['created_at' => now()->subHours(5)]);
        $new = logEntry(['created_at' => now()->subHour()]);

        Livewire::test(ListActivityLogs::class)
            ->sortTable('created_at', 'asc')
            ->assertCanSeeTableRecords([$old, $new], inOrder: true)
            ->sortTable('created_at', 'desc')
            ->assertCanSeeTableRecords([$new, $old], inOrder: true);
    });
});

describe('entry page', function () {
    test('an HTTP entry shows summary, request, trace and context', function () {
        $entry = logEntry([
            'action' => 'http.request', 'message' => 'GET /orders → 200', 'status_code' => 200, 'duration_ms' => 84, 'http_method' => 'GET',
            'url' => 'https://app.test/orders', 'ip_address' => '10.9.9.9', 'user_agent' => 'Mozilla/5.0 Test', 'correlation_id' => 'corr-VIEW',
            'context' => ['route' => 'orders.index', 'note' => 'مرحبا'],
        ]);

        Livewire::test(ViewActivityLog::class, ['record' => $entry->id])
            ->assertOk()
            ->assertSee('GET /orders → 200')
            ->assertSee('https://app.test/orders')
            ->assertSee('10.9.9.9')
            ->assertSee('Mozilla/5.0 Test')
            ->assertSee('84')
            ->assertSee('corr-VIEW')
            ->assertSee('orders.index')
            ->assertSee('مرحبا')
            ->assertDontSee('\\u0645', false);
    });

    test('a non-HTTP entry hides the request section', function () {
        $entry = logEntry(['channel' => 'log', 'action' => 'log.info', 'http_method' => null, 'url' => null, 'status_code' => null]);

        Livewire::test(ViewActivityLog::class, ['record' => $entry->id])->assertOk()->assertDontSee('Duration');
    });

    test('the exception section only shows when the entry has an exception', function () {
        $fixture = logFixture();

        Livewire::test(ViewActivityLog::class, ['record' => $fixture['webError']->id])
            ->assertSee('Location')
            ->assertSee('pay failed')
            ->assertSee('/app/Pay.php:10')
            ->assertSee('#1 b');

        Livewire::test(ViewActivityLog::class, ['record' => $fixture['webInfo']->id])->assertDontSee('Location');
    });

    test('a user actor shows their name and e-mail, a system actor does not', function () {
        $user = User::factory()->create(['name' => 'Aisha Owner', 'email' => 'aisha@example.test']);
        $byUser = logEntry(['actor_type' => User::class, 'actor_id' => (string) $user->id]);
        $bySystem = logEntry(['actor_type' => 'system']);

        Livewire::test(ViewActivityLog::class, ['record' => $byUser->id])
            ->assertSee('Aisha Owner (aisha@example.test)')
            ->assertSee("User #{$user->id}");
        Livewire::test(ViewActivityLog::class, ['record' => $bySystem->id])
            ->assertSee('System')
            ->assertDontSee('Aisha Owner');
    });

    test('a subscription subject links to its admin page and a merchant subject does not', function () {
        $bySubscription = logEntry(['subject_type' => Subscription::class, 'subject_id' => '42']);
        $byMerchant = logEntry(['subject_type' => Merchant::class, 'subject_id' => '9']);
        $url = SubscriptionResource::getUrl('view', ['record' => 42]);

        Livewire::test(ViewActivityLog::class, ['record' => $bySubscription->id])->assertSee($url, false)->assertSee('Subscription #42');
        Livewire::test(ViewActivityLog::class, ['record' => $byMerchant->id])->assertSee('Merchant #9')->assertDontSee('/admin/subscriptions/', false);
    });

    test('empty context reads no context', function () {
        $entry = logEntry(['context' => null]);

        Livewire::test(ViewActivityLog::class, ['record' => $entry->id])->assertSee('No context');
    });

    test('the correlation link lists every entry of that request on the All tab with any time', function () {
        $fixture = logFixture();
        $link = ListActivityLogs::getUrl([
            'tab' => 'all',
            'filters' => ['correlation' => ['value' => 'corr-A'], 'period' => ['value' => 'all']],
        ]);

        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        expect($query['tab'])->toBe('all')
            ->and($query['filters']['correlation']['value'])->toBe('corr-A')
            ->and($query['filters']['period']['value'])->toBe('all');

        Livewire::test(ViewActivityLog::class, ['record' => $fixture['webInfo']->id])->assertSee(e($link), false);

        Livewire::withQueryParams($query)->test(ListActivityLogs::class)
            ->assertSet('activeTab', 'all')
            ->assertCanSeeTableRecords(logsOf($fixture, ['webInfo', 'webhookReceived', 'modelUpdated', 'outbound']))
            ->assertCanNotSeeTableRecords(logsOf($fixture, ['webWarning', 'webError', 'userLogin']));
    });
});

describe('performance and self-logging', function () {
    test('query count does not grow with rows and no count query runs', function () {
        logFixture();
        $queries = function (): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(ListActivityLogs::class)->assertOk();
            $log = collect(DB::getQueryLog())->pluck('query');
            DB::disableQueryLog();

            return $log->all();
        };

        $queries();
        ActivityLog::factory()->count(5)->create(['merchant_id' => 500, 'created_at' => now()->subMinutes(5)]);
        $withFiveMore = $queries();
        ActivityLog::factory()->count(5)->create(['merchant_id' => 500, 'created_at' => now()->subMinutes(5)]);
        $withTenMore = $queries();

        expect(count($withTenMore))->toBe(count($withFiveMore));
        foreach ($withTenMore as $sql) {
            expect($sql)->not->toMatch('/count\(\*\)/i');
        }
    });

    test('livewire update calls are not logged but ordinary paths are', function () {
        Route::middleware('web')->post('/livewire-abc123/update', fn () => 'ok');
        Route::middleware('web')->post('/livewire-abc123/other', fn () => 'ok');

        $this->post('/livewire-abc123/update')->assertOk();
        expect(ActivityLog::where('action', 'http.request')->where('url', 'like', '%/livewire-abc123/update')->count())->toBe(0);

        $this->post('/livewire-abc123/other')->assertOk();
        expect(ActivityLog::where('action', 'http.request')->where('url', 'like', '%/livewire-abc123/other')->count())->toBe(1);
        expect(config('activity-log.http.exclude'))->toContain('livewire*/update');
    });
});

describe('helpers and enum', function () {
    test('actor labels', function (?string $type, ?string $id, string $label) {
        expect(ActivityLog::factory()->make(['actor_type' => $type, 'actor_id' => $id])->actorLabel())->toBe($label);
    })->with([
        'user' => [User::class, '7', 'User #7'],
        'system' => ['system', null, 'System'],
        'merchant' => ['salla_merchant', '777', 'Salla merchant 777'],
        'none' => [null, null, 'Guest / none'],
        'unknown kind' => ['robot', '3', 'robot #3'],
    ]);

    test('subject label and url', function () {
        $none = ActivityLog::factory()->make(['subject_type' => null, 'subject_id' => null]);
        $merchant = ActivityLog::factory()->make(['subject_type' => Merchant::class, 'subject_id' => '5']);
        $subscription = ActivityLog::factory()->make(['subject_type' => Subscription::class, 'subject_id' => '5']);

        expect($none->subjectLabel())->toBeNull()->and($none->subjectUrl())->toBeNull()
            ->and($merchant->subjectLabel())->toBe('Merchant #5')->and($merchant->subjectUrl())->toBeNull()
            ->and($subscription->subjectLabel())->toBe('Subscription #5')
            ->and($subscription->subjectUrl())->toBe(SubscriptionResource::getUrl('view', ['record' => 5]));
    });

    test('actor user resolves only for user actors', function () {
        $user = User::factory()->create();

        expect(ActivityLog::factory()->make(['actor_type' => User::class, 'actor_id' => (string) $user->id])->actorUser()?->is($user))->toBeTrue()
            ->and(ActivityLog::factory()->make(['actor_type' => 'system', 'actor_id' => (string) $user->id])->actorUser())->toBeNull();
    });

    test('level labels, colors, icons and problem levels', function () {
        $expected = [
            'debug' => ['Debug', 'gray'], 'info' => ['Info', 'info'], 'notice' => ['Notice', 'primary'], 'warning' => ['Warning', 'warning'],
            'error' => ['Error', 'danger'], 'critical' => ['Critical', 'danger'], 'alert' => ['Alert', 'danger'], 'emergency' => ['Emergency', 'danger'],
        ];

        foreach (ActivityLevel::cases() as $level) {
            expect($level->getLabel())->toBe($expected[$level->value][0])
                ->and($level->getColor())->toBe($expected[$level->value][1])
                ->and($level->getIcon())->not->toBeNull();
        }

        expect(ActivityLevel::problems())->toBe([ActivityLevel::Warning, ActivityLevel::Error, ActivityLevel::Critical, ActivityLevel::Alert, ActivityLevel::Emergency]);
    });
});
