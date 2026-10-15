<?php

use App\Filament\Resources\Funds\Pages\CreateFund;
use App\Filament\Resources\Funds\Pages\EditFund;
use App\Filament\Resources\Funds\Pages\ListFunds;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\BankApplication;
use App\Models\Emission;
use App\Models\Expense;
use App\Models\ExpenseHistory;
use App\Models\Fund;
use App\Models\FundApplication as FundClassification;
use App\Models\FundBalanceHistory;
use App\Models\FundName;
use App\Models\FundType;
use App\Models\Guarantee;
use App\Models\User;
use App\Rules\BankApplicationBelongsToAccount;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('registers a fund linked to a global account and application, deriving legacy bank fields', function () {
    $this->actingAs(makeFundLinkAdminUser());

    [$emission, $fundType, $fundName, $classification] = makeFundLinkClassification();
    $account = BankAccount::factory()->create([
        'agency_number' => '0123',
        'agency_digit' => '4',
        'account_number' => '0012345',
        'account_digit' => '6',
    ]);
    $application = BankApplication::factory()->create([
        'bank_account_id' => $account->id,
        'codigo_produto' => '00042',
    ]);

    Livewire::test(CreateFund::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'fund_type_id' => $fundType->id,
            'fund_name_id' => $fundName->id,
            'fund_application_id' => $classification->id,
            'bank_account_id' => $account->id,
            'bank_application_id' => $application->id,
            'balance' => '150.000,25',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $fund = Fund::query()->first();

    expect($fund)->not->toBeNull()
        ->and($fund->bank_account_id)->toBe($account->id)
        ->and($fund->bank_application_id)->toBe($application->id)
        ->and($fund->bank_id)->toBe($account->bank_id)
        ->and($fund->agency)->toBe('0123-4')
        ->and($fund->account)->toBe('0012345-6')
        ->and($fund->balance)->toBe('150000.25');
});

it('allows linking a fund to an account while the application is still pending', function () {
    $this->actingAs(makeFundLinkAdminUser());

    [$emission, $fundType, $fundName, $classification] = makeFundLinkClassification();
    $account = BankAccount::factory()->create();

    Livewire::test(CreateFund::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'fund_type_id' => $fundType->id,
            'fund_name_id' => $fundName->id,
            'fund_application_id' => $classification->id,
            'bank_account_id' => $account->id,
            'bank_application_id' => null,
            'balance' => '10.000,00',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $fund = Fund::query()->first();

    expect($fund->bank_account_id)->toBe($account->id)
        ->and($fund->bank_application_id)->toBeNull()
        ->and($fund->bank_id)->toBe($account->bank_id)
        ->and($fund->account)->toBe($account->formattedAccount());
});

it('keeps legacy registration operable without the new relationships', function () {
    $this->actingAs(makeFundLinkAdminUser());

    [$emission, $fundType, $fundName, $classification] = makeFundLinkClassification();
    $bank = Bank::factory()->create();

    Livewire::test(CreateFund::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'fund_type_id' => $fundType->id,
            'fund_name_id' => $fundName->id,
            'fund_application_id' => $classification->id,
            'bank_id' => $bank->id,
            'agency' => '1234-5',
            'account' => '12345-6',
            'balance' => '20.000,00',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $fund = Fund::query()->first();

    expect($fund->bank_account_id)->toBeNull()
        ->and($fund->bank_application_id)->toBeNull()
        ->and($fund->bank_id)->toBe($bank->id)
        ->and($fund->agency)->toBe('1234-5')
        ->and($fund->account)->toBe('12345-6')
        ->and($fund->isBalanceBelowMinimum())->toBeFalse();
});

it('still requires manual bank fields when no account is selected', function () {
    $this->actingAs(makeFundLinkAdminUser());

    Livewire::test(CreateFund::class)
        ->call('create')
        ->assertHasFormErrors([
            'bank_id' => 'required',
            'agency' => 'required',
            'account' => 'required',
        ]);
});

it('rejects an application that belongs to another account', function () {
    $this->actingAs(makeFundLinkAdminUser());

    [$emission, $fundType, $fundName, $classification] = makeFundLinkClassification();
    $account = BankAccount::factory()->create();
    $otherApplication = BankApplication::factory()->create();

    Livewire::test(CreateFund::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'fund_type_id' => $fundType->id,
            'fund_name_id' => $fundName->id,
            'fund_application_id' => $classification->id,
            'bank_account_id' => $account->id,
            'bank_application_id' => $otherApplication->id,
            'balance' => '5.000,00',
        ])
        ->call('create')
        ->assertHasFormErrors(['bank_application_id']);

    expect(Fund::query()->count())->toBe(0);
});

it('validates the account and application pair through the dedicated rule', function () {
    $account = BankAccount::factory()->create();
    $application = BankApplication::factory()->create(['bank_account_id' => $account->id]);
    $foreignApplication = BankApplication::factory()->create();

    $failures = [];

    $fail = function (string $message) use (&$failures): void {
        $failures[] = $message;
    };

    (new BankApplicationBelongsToAccount)
        ->setData(['bank_account_id' => $account->id])
        ->validate('bank_application_id', $application->id, $fail);

    expect($failures)->toBe([]);

    (new BankApplicationBelongsToAccount)
        ->setData(['bank_account_id' => $account->id])
        ->validate('bank_application_id', $foreignApplication->id, $fail);

    expect($failures)->toBe(['A aplicação bancária pertence a outra conta.']);

    (new BankApplicationBelongsToAccount)
        ->setData(['bank_account_id' => null])
        ->validate('bank_application_id', $application->id, $fail);

    expect($failures)->toHaveCount(2);

    (new BankApplicationBelongsToAccount)
        ->setData(['bank_account_id' => $account->id])
        ->validate('bank_application_id', null, $fail);

    expect($failures)->toHaveCount(2);
});

it('reuses one global account across emissions', function () {
    $this->actingAs(makeFundLinkAdminUser());

    $account = BankAccount::factory()->create();
    $application = BankApplication::factory()->create(['bank_account_id' => $account->id]);

    foreach (['Operação Gama', 'Operação Delta'] as $emissionName) {
        [$emission, $fundType, $fundName, $classification] = makeFundLinkClassification($emissionName);

        Livewire::test(CreateFund::class)
            ->fillForm([
                'emission_id' => $emission->id,
                'fund_type_id' => $fundType->id,
                'fund_name_id' => $fundName->id,
                'fund_application_id' => $classification->id,
                'bank_account_id' => $account->id,
                'bank_application_id' => $application->id,
                'balance' => '30.000,00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    expect(Fund::query()->where('bank_account_id', $account->id)->count())->toBe(2)
        ->and($application->fresh()->isShared())->toBeTrue();
});

it('shows the shared note on the fund form for a shared application', function () {
    $this->actingAs(makeFundLinkAdminUser());

    $application = BankApplication::factory()->create();

    $first = Fund::factory()->create([
        'bank_account_id' => $application->bank_account_id,
        'bank_application_id' => $application->id,
    ]);
    Fund::factory()->create([
        'emission_id' => Emission::factory()->create()->id,
        'bank_account_id' => $application->bank_account_id,
        'bank_application_id' => $application->id,
    ]);

    Livewire::test(EditFund::class, ['record' => $first->getRouteKey()])
        ->assertOk()
        ->assertSee('Compartilhado')
        ->assertSee('valor manual legado')
        ->assertSee('não é uma parcela de alocação aprovada')
        ->assertDontSee('apenas a parcela atribuída');
});

it('keeps the legacy uniqueness rule for linked funds', function () {
    $this->actingAs(makeFundLinkAdminUser());

    [$emission, $fundType, $fundName, $classification] = makeFundLinkClassification();
    $account = BankAccount::factory()->create();

    Fund::factory()->create([
        'emission_id' => $emission->id,
        'fund_type_id' => $fundType->id,
        'fund_name_id' => $fundName->id,
        'fund_application_id' => $classification->id,
        'bank_id' => $account->bank_id,
        'agency' => $account->formattedAgency(),
        'account' => $account->formattedAccount(),
        'bank_account_id' => $account->id,
    ]);

    Livewire::test(CreateFund::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'fund_type_id' => $fundType->id,
            'fund_name_id' => $fundName->id,
            'fund_application_id' => $classification->id,
            'bank_account_id' => $account->id,
            'bank_application_id' => null,
            'balance' => '7.000,00',
        ])
        ->call('create')
        ->assertHasFormErrors(['account']);

    expect(Fund::query()->count())->toBe(1);
});

it('re-derives legacy fields on edit without touching balances', function () {
    $this->actingAs(makeFundLinkAdminUser());

    $fund = Fund::factory()->create([
        'agency' => '1111-1',
        'account' => '22222-2',
        'balance' => '50000.00',
    ]);
    $account = BankAccount::factory()->create([
        'account_number' => '0098765',
        'account_digit' => '4',
    ]);

    Livewire::test(EditFund::class, ['record' => $fund->getRouteKey()])
        ->fillForm(['bank_account_id' => $account->id])
        ->call('save')
        ->assertHasNoFormErrors();

    $fund->refresh();

    expect($fund->bank_account_id)->toBe($account->id)
        ->and($fund->bank_id)->toBe($account->bank_id)
        ->and($fund->agency)->toBe($account->formattedAgency())
        ->and($fund->account)->toBe('0098765-4')
        ->and($fund->balance)->toBe('50000.00');
});

it('never copies the account-level conta azul id to linked funds', function () {
    $this->actingAs(makeFundLinkAdminUser());

    [$emission, $fundType, $fundName, $classification] = makeFundLinkClassification();
    $account = BankAccount::factory()->create([
        'conta_azul_account_id' => 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff',
    ]);

    Livewire::test(CreateFund::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'fund_type_id' => $fundType->id,
            'fund_name_id' => $fundName->id,
            'fund_application_id' => $classification->id,
            'bank_account_id' => $account->id,
            'balance' => '12.000,00',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Fund::query()->first()->conta_azul_account_id)->toBeNull()
        ->and($account->fresh()->conta_azul_account_id)->toBe('bbbbbbbb-cccc-dddd-eeee-ffffffffffff');
});

it('lists funds with the new relationship columns available', function () {
    $this->actingAs(makeFundLinkAdminUser());

    $application = BankApplication::factory()->create(['codigo_produto' => '00555']);
    Fund::factory()->create([
        'bank_account_id' => $application->bank_account_id,
        'bank_application_id' => $application->id,
    ]);

    Livewire::test(ListFunds::class)
        ->assertOk()
        ->assertCanSeeTableRecords(Fund::query()->get()->all());
});

it('reports the registration impact without changing any data', function () {
    $application = BankApplication::factory()->create();
    $fund = Fund::factory()->create([
        'bank_account_id' => $application->bank_account_id,
        'bank_application_id' => $application->id,
        'conta_azul_account_id' => 'cccccccc-dddd-eeee-ffff-000000000000',
    ]);
    FundBalanceHistory::factory()->create(['fund_id' => $fund->id]);
    Guarantee::factory()->create(['fund_id' => $fund->id]);
    Expense::factory()->create(['conta_azul_bill_id' => 'bill--impact-1']);

    $countsBefore = [
        Fund::query()->count(),
        FundBalanceHistory::query()->count(),
        Guarantee::query()->count(),
        Expense::query()->count(),
        ExpenseHistory::query()->count(),
    ];

    $this->artisan('funds:registrations:impact')
        ->expectsOutputToContain('SOMENTE LEITURA')
        ->expectsOutputToContain('Fundos cadastrados')
        ->expectsOutputToContain('Garantias vinculadas a fundos')
        ->expectsOutputToContain('Leitores financeiros de Fundos')
        ->assertExitCode(0);

    expect([
        Fund::query()->count(),
        FundBalanceHistory::query()->count(),
        Guarantee::query()->count(),
        Expense::query()->count(),
        ExpenseHistory::query()->count(),
    ])->toBe($countsBefore);
});

it('reports the registration impact as json', function () {
    Fund::factory()->create();

    $this->artisan('funds:registrations:impact', ['--format' => 'json'])
        ->assertExitCode(0);
});

/**
 * @return array{Emission, FundType, FundName, FundClassification}
 */
function makeFundLinkClassification(?string $emissionName = null): array
{
    $emission = Emission::factory()->create($emissionName !== null ? ['name' => $emissionName] : []);
    $fundType = FundType::factory()->create();
    $fundName = FundName::factory()->create(['fund_type_id' => $fundType->id]);
    $classification = FundClassification::factory()->create();

    return [$emission, $fundType, $fundName, $classification];
}

function makeFundLinkAdminUser(): User
{
    $user = User::factory()->withTwoFactor()->create([
        'email' => fake()->unique()->safeEmail(),
    ]);
    $user->assignRole('admin');

    return $user;
}
