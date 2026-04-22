<?php

use App\Filament\Resources\Funds\Pages\EditFund;
use App\Filament\Resources\Funds\RelationManagers\FundBalanceHistoriesRelationManager;
use App\Models\Fund;
use App\Models\FundBalanceHistory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('formats the saved balance correctly when reopening a fund for editing', function () {
    $this->actingAs(makeFundBalanceAdminUser());

    $fund = Fund::factory()->create([
        'balance' => 750.00,
        'balance_updated_at' => now(),
    ]);

    Livewire::test(EditFund::class, [
        'record' => $fund->getRouteKey(),
    ])
        ->assertFormSet([
            'balance' => '750,00',
        ]);
});

it('stores the previous monthly balance in history when a pending fund is updated', function () {
    $this->actingAs(makeFundBalanceAdminUser());

    Carbon::setTestNow('2026-05-01 09:00:00');

    $fund = Fund::factory()->create([
        'balance' => 125000.40,
        'balance_updated_at' => '2026-04-10 08:00:00',
    ]);

    Livewire::test(EditFund::class, [
        'record' => $fund->getRouteKey(),
    ])
        ->fillForm([
            'balance' => '130.000,90',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $fund->refresh();

    expect($fund->balance)->toBe('130000.90')
        ->and($fund->balance_updated_at?->toDateTimeString())->toBe('2026-05-01 09:00:00');

    $history = $fund->balanceHistories()->first();

    expect($history)->not->toBeNull()
        ->and($history?->date?->toDateString())->toBe('2026-04-30')
        ->and($history?->balance)->toBe('125000.40');
});

it('creates monthly balance snapshots only once for funds pending update', function () {
    Carbon::setTestNow('2026-05-01 08:00:00');

    $pendingFund = Fund::factory()->create([
        'balance' => 88000.55,
        'balance_updated_at' => '2026-04-15 12:00:00',
    ]);

    $updatedFund = Fund::factory()->create([
        'balance' => 91000.10,
        'balance_updated_at' => '2026-05-01 07:30:00',
    ]);

    $this->artisan('app:snapshot-monthly-fund-balances')
        ->expectsOutput('1 historico(s) de saldo foram registrados.')
        ->assertExitCode(0);

    $this->artisan('app:snapshot-monthly-fund-balances')
        ->expectsOutput('0 historico(s) de saldo foram registrados.')
        ->assertExitCode(0);

    $pendingHistory = $pendingFund->balanceHistories()->first();

    expect($pendingHistory)->not->toBeNull()
        ->and($pendingHistory?->date?->toDateString())->toBe('2026-04-30')
        ->and($pendingHistory?->balance)->toBe('88000.55')
        ->and($updatedFund->balanceHistories()->count())->toBe(0)
        ->and(FundBalanceHistory::query()->count())->toBe(1);
});

it('shows balance history records on the fund relation manager', function () {
    $this->actingAs(makeFundBalanceAdminUser());

    $fund = Fund::factory()->create();
    $latestHistory = FundBalanceHistory::factory()->create([
        'fund_id' => $fund->id,
        'date' => '2026-04-30',
        'balance' => 120000.00,
    ]);
    $olderHistory = FundBalanceHistory::factory()->create([
        'fund_id' => $fund->id,
        'date' => '2026-03-31',
        'balance' => 115000.00,
    ]);

    Livewire::test(FundBalanceHistoriesRelationManager::class, [
        'ownerRecord' => $fund,
        'pageClass' => EditFund::class,
    ])
        ->assertCanSeeTableRecords([$latestHistory, $olderHistory], inOrder: true);
});

function makeFundBalanceAdminUser(): User
{
    $user = User::factory()->withTwoFactor()->create([
        'email' => fake()->unique()->safeEmail(),
    ]);
    $user->assignRole('admin');

    return $user;
}
