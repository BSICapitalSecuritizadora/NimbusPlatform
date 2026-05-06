<?php

use App\Filament\Resources\Funds\Pages\CreateFund;
use App\Filament\Resources\Funds\Pages\EditFund;
use App\Filament\Resources\Funds\RelationManagers\FundBalanceHistoriesRelationManager;
use App\Mail\FundBalanceBelowMinimumMail;
use App\Models\Bank;
use App\Models\Emission;
use App\Models\Fund;
use App\Models\FundApplication;
use App\Models\FundBalanceHistory;
use App\Models\FundName;
use App\Models\FundType;
use App\Models\Investor;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
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

it('shows an informational warning when creating a fund with balance below the minimum value', function () {
    $this->actingAs(makeFundBalanceAdminUser());

    $emission = Emission::factory()->create();
    $fundType = FundType::factory()->create();
    $fundName = FundName::factory()->create([
        'fund_type_id' => $fundType->id,
    ]);
    $fundApplication = FundApplication::factory()->create();
    $bank = Bank::factory()->create();

    Livewire::test(CreateFund::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'fund_type_id' => $fundType->id,
            'fund_name_id' => $fundName->id,
            'fund_application_id' => $fundApplication->id,
            'bank_id' => $bank->id,
            'agency' => '1234-5',
            'account' => '12345-6',
            'balance' => '49.000,00',
            'minimum_balance' => '50.000,00',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Atencao: o saldo informado esta abaixo do valor minimo definido.');

    expect(Fund::query()->first())
        ->not->toBeNull()
        ->and(Fund::query()->first()?->balance)->toBe('49000.00')
        ->and(Fund::query()->first()?->minimum_balance)->toBe('50000.00');
});

it('sends the below minimum email after creating a fund when the emission has linked investors', function () {
    $this->actingAs(makeFundBalanceAdminUser());

    Mail::fake();
    Carbon::setTestNow('2026-05-01 10:00:00');

    $emission = Emission::factory()->create([
        'name' => 'Emissao XYZ',
        'description' => 'Aporte complementar necessario.',
    ]);
    $primaryInvestor = Investor::factory()->create([
        'email' => 'investidor.um@example.com',
    ]);
    $secondaryInvestor = Investor::factory()->create([
        'email' => 'investidor.dois@example.com',
    ]);
    $emission->investors()->attach([$primaryInvestor->id, $secondaryInvestor->id]);

    $fundType = FundType::factory()->create();
    $fundName = FundName::factory()->create([
        'fund_type_id' => $fundType->id,
    ]);
    $fundApplication = FundApplication::factory()->create();
    $bank = Bank::factory()->create();

    Livewire::test(CreateFund::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'fund_type_id' => $fundType->id,
            'fund_name_id' => $fundName->id,
            'fund_application_id' => $fundApplication->id,
            'bank_id' => $bank->id,
            'agency' => '1234-5',
            'account' => '12345-6',
            'balance' => '49.000,00',
            'minimum_balance' => '50.000,00',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Atencao: o saldo informado esta abaixo do valor minimo definido.');

    $fund = Fund::query()->firstOrFail();

    Mail::assertSent(FundBalanceBelowMinimumMail::class, function (FundBalanceBelowMinimumMail $mail) use ($primaryInvestor, $secondaryInvestor): bool {
        $html = $mail->render();

        return $mail->hasTo($primaryInvestor->email)
            && $mail->hasTo($secondaryInvestor->email)
            && ($mail->envelope()->subject === 'Saldo abaixo do mínimo (Emissao XYZ - Conta 12345-6)')
            && str_contains($html, 'Emissao XYZ')
            && str_contains($html, 'Conta 12345-6')
            && str_contains($html, '49.000,00')
            && str_contains($html, '50.000,00')
            && str_contains($html, '01/05/2026 10:00')
            && str_contains($html, 'Aporte complementar necessario.');
    });

    expect($fund->refresh()->minimum_balance_alert_sent_at?->toDateTimeString())->toBe('2026-05-01 10:00:00')
        ->and($fund->minimum_balance_alert_balance)->toBe('49000.00')
        ->and($fund->minimum_balance_alert_minimum_balance)->toBe('50000.00')
        ->and($fund->minimum_balance_alert_recipients_hash)->not->toBeNull();
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

it('does not show the below minimum warning when the saved balance is equal to or above the minimum value', function () {
    $this->actingAs(makeFundBalanceAdminUser());

    Mail::fake();

    $emission = Emission::factory()->create();
    $investor = Investor::factory()->create([
        'email' => 'investidor.acima@example.com',
    ]);
    $emission->investors()->attach($investor->id);

    $fund = Fund::factory()->create([
        'emission_id' => $emission->id,
        'balance' => 60000.00,
        'minimum_balance' => 55000.00,
        'balance_updated_at' => now(),
        'minimum_balance_alert_sent_at' => '2026-04-30 12:00:00',
        'minimum_balance_alert_balance' => 49000.00,
        'minimum_balance_alert_minimum_balance' => 55000.00,
        'minimum_balance_alert_recipients_hash' => hash('sha256', 'investidor.acima@example.com'),
    ]);

    Livewire::test(EditFund::class, [
        'record' => $fund->getRouteKey(),
    ])
        ->fillForm([
            'balance' => '60.000,00',
            'minimum_balance' => '55.000,00',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotNotified('Atencao: o saldo informado esta abaixo do valor minimo definido.');

    Mail::assertNothingSent();
    expect($fund->refresh()->minimum_balance_alert_sent_at)->toBeNull()
        ->and($fund->minimum_balance_alert_balance)->toBeNull()
        ->and($fund->minimum_balance_alert_minimum_balance)->toBeNull()
        ->and($fund->minimum_balance_alert_recipients_hash)->toBeNull();
});

it('does not send the below minimum email when the fund has no minimum balance configured', function () {
    Mail::fake();

    $emission = Emission::factory()->create();
    $investor = Investor::factory()->create([
        'email' => 'investidor.sem-minimo@example.com',
    ]);
    $emission->investors()->attach($investor->id);

    Fund::factory()->create([
        'emission_id' => $emission->id,
        'balance' => 49000.00,
        'minimum_balance' => null,
    ]);

    $this->artisan('app:send-fund-minimum-balance-alerts')
        ->expectsOutput('0 alerta(s) de saldo minimo enviados.')
        ->assertExitCode(0);

    Mail::assertNothingSent();
});

it('does not resend the below minimum email without a real state change', function () {
    Mail::fake();
    Carbon::setTestNow('2026-05-01 08:00:00');

    $emission = Emission::factory()->create([
        'name' => 'Emissao Duplicidade',
    ]);
    $investor = Investor::factory()->create([
        'email' => 'investidor.duplicidade@example.com',
    ]);
    $emission->investors()->attach($investor->id);

    $fund = Fund::factory()->create([
        'emission_id' => $emission->id,
        'account' => '99887-6',
        'balance' => 49000.00,
        'minimum_balance' => 50000.00,
        'minimum_balance_alert_sent_at' => null,
        'minimum_balance_alert_balance' => null,
        'minimum_balance_alert_minimum_balance' => null,
        'minimum_balance_alert_recipients_hash' => null,
    ]);

    $this->artisan('app:send-fund-minimum-balance-alerts')
        ->expectsOutput('1 alerta(s) de saldo minimo enviados.')
        ->assertExitCode(0);

    Mail::assertSent(FundBalanceBelowMinimumMail::class, 1);

    $firstSentAt = $fund->refresh()->minimum_balance_alert_sent_at?->toDateTimeString();

    Mail::fake();
    Carbon::setTestNow('2026-05-01 09:00:00');

    $this->artisan('app:send-fund-minimum-balance-alerts')
        ->expectsOutput('0 alerta(s) de saldo minimo enviados.')
        ->assertExitCode(0);

    Mail::assertNothingSent();
    expect($fund->refresh()->minimum_balance_alert_sent_at?->toDateTimeString())->toBe($firstSentAt);

    $fund->update([
        'balance' => 47000.00,
    ]);

    Mail::fake();
    Carbon::setTestNow('2026-05-01 10:00:00');

    $this->artisan('app:send-fund-minimum-balance-alerts')
        ->expectsOutput('1 alerta(s) de saldo minimo enviados.')
        ->assertExitCode(0);

    Mail::assertSent(FundBalanceBelowMinimumMail::class, function (FundBalanceBelowMinimumMail $mail): bool {
        return $mail->envelope()->subject === 'Saldo abaixo do mínimo (Emissao Duplicidade - Conta 99887-6)';
    });

    expect($fund->refresh()->minimum_balance_alert_sent_at?->toDateTimeString())->toBe('2026-05-01 10:00:00')
        ->and($fund->minimum_balance_alert_balance)->toBe('47000.00')
        ->and($fund->minimum_balance_alert_minimum_balance)->toBe('50000.00');
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
