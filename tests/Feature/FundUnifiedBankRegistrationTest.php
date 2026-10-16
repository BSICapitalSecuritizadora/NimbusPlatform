<?php

use App\Filament\Resources\FundAllocations\Pages\ListFundAllocations;
use App\Filament\Resources\Funds\FundResource;
use App\Filament\Resources\Funds\Pages\CreateFund;
use App\Filament\Resources\Funds\Pages\EditFund;
use App\Filament\Resources\Funds\Pages\ListFunds;
use App\Jobs\SyncContaAzulExpensesJob;
use App\Models\BankAccount;
use App\Models\BankApplication;
use App\Models\ContaAzulImportBatch;
use App\Models\ContaAzulImportedBill;
use App\Models\ContaAzulToken;
use App\Models\Emission;
use App\Models\Expense;
use App\Models\ExpenseHistory;
use App\Models\Fund;
use App\Models\FundAllocationPeriod;
use App\Models\FundApplication as FundClassification;
use App\Models\FundBalanceHistory;
use App\Models\FundName;
use App\Models\FundType;
use App\Models\Guarantee;
use App\Models\User;
use App\Services\ContaAzulClient;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('reuses one global account for several funds in the same emission', function () {
    $this->actingAs(makeUnifiedFundAdminUser());

    [$emission, $fundType, $fundName] = makeUnifiedFundClassification();
    $account = BankAccount::factory()->create();
    $firstApplication = BankApplication::factory()->create(['bank_account_id' => $account->id]);
    $secondApplication = BankApplication::factory()->create(['bank_account_id' => $account->id]);

    foreach ([$firstApplication, $secondApplication] as $application) {
        Livewire::test(CreateFund::class)
            ->fillForm([
                'emission_id' => $emission->id,
                'fund_type_id' => $fundType->id,
                'fund_name_id' => $fundName->id,
                'fund_application_id' => FundClassification::factory()->create()->id,
                'bank_account_id' => $account->id,
                'bank_application_id' => $application->id,
                'balance' => '10.000,00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    expect(BankAccount::query()->count())->toBe(1)
        ->and(Fund::query()->where('bank_account_id', $account->id)->count())->toBe(2);
});

it('reuses one account across emissions with distinct conta azul uuids', function () {
    $this->actingAs(makeUnifiedFundAdminUser());

    $account = BankAccount::factory()->create();

    foreach ([
        'Operação Norte' => '11111111-1111-1111-1111-111111111111',
        'Operação Sul' => '22222222-2222-2222-2222-222222222222',
    ] as $emissionName => $uuid) {
        [$emission, $fundType, $fundName, $classification] = makeUnifiedFundClassification($emissionName);

        Livewire::test(CreateFund::class)
            ->fillForm([
                'emission_id' => $emission->id,
                'fund_type_id' => $fundType->id,
                'fund_name_id' => $fundName->id,
                'fund_application_id' => $classification->id,
                'bank_account_id' => $account->id,
                'conta_azul_account_id' => $uuid,
                'balance' => '10.000,00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    expect(Fund::query()->where('bank_account_id', $account->id)->count())->toBe(2)
        ->and(Fund::query()->where('conta_azul_account_id', '11111111-1111-1111-1111-111111111111')->exists())->toBeTrue()
        ->and(Fund::query()->where('conta_azul_account_id', '22222222-2222-2222-2222-222222222222')->exists())->toBeTrue();
});

it('links two different bank applications on the same physical account', function () {
    $this->actingAs(makeUnifiedFundAdminUser());

    [$emission, $fundType, $fundName] = makeUnifiedFundClassification();
    $account = BankAccount::factory()->create();
    $cdb = BankApplication::factory()->create([
        'bank_account_id' => $account->id,
        'codigo_produto' => '00042',
        'nome_produto' => 'CDB DI',
    ]);
    $lca = BankApplication::factory()->create([
        'bank_account_id' => $account->id,
        'codigo_produto' => '00043',
        'nome_produto' => 'LCA Pós',
    ]);

    foreach ([$cdb, $lca] as $application) {
        Livewire::test(CreateFund::class)
            ->fillForm([
                'emission_id' => $emission->id,
                'fund_type_id' => $fundType->id,
                'fund_name_id' => $fundName->id,
                'fund_application_id' => FundClassification::factory()->create()->id,
                'bank_account_id' => $account->id,
                'bank_application_id' => $application->id,
                'balance' => '10.000,00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    expect($account->bankApplications()->count())->toBe(2)
        ->and(Fund::query()->where('bank_application_id', $cdb->id)->exists())->toBeTrue()
        ->and(Fund::query()->where('bank_application_id', $lca->id)->exists())->toBeTrue();
});

it('rejects a mismatched account and application pair at the model level', function () {
    $account = BankAccount::factory()->create();
    $foreignApplication = BankApplication::factory()->create();

    expect(fn () => Fund::factory()->create([
        'bank_account_id' => $account->id,
        'bank_application_id' => $foreignApplication->id,
    ]))->toThrow(InvalidArgumentException::class, 'A aplicação bancária pertence a outra conta.');

    expect(Fund::query()->count())->toBe(0);
});

it('preserves leading zeros and check digits from the global account', function () {
    $this->actingAs(makeUnifiedFundAdminUser());

    [$emission, $fundType, $fundName, $classification] = makeUnifiedFundClassification();
    $account = BankAccount::factory()->create([
        'agency_number' => '0123',
        'agency_digit' => 'x',
        'account_number' => '0012345',
        'account_digit' => '6',
    ]);

    Livewire::test(CreateFund::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'fund_type_id' => $fundType->id,
            'fund_name_id' => $fundName->id,
            'fund_application_id' => $classification->id,
            'bank_account_id' => $account->id,
            'balance' => '10.000,00',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $fund = Fund::query()->first();

    expect($fund->agency)->toBe('0123-X')
        ->and($fund->account)->toBe('0012345-6');
});

it('shows the physical summary and pending note until the application is chosen', function () {
    $this->actingAs(makeUnifiedFundAdminUser());

    $account = BankAccount::factory()->create();
    $application = BankApplication::factory()->create(['bank_account_id' => $account->id]);
    $fund = Fund::factory()->create([
        'bank_id' => $account->bank_id,
        'agency' => $account->formattedAgency(),
        'account' => $account->formattedAccount(),
        'bank_account_id' => $account->id,
        'bank_application_id' => null,
    ]);

    Livewire::test(EditFund::class, ['record' => $fund->getRouteKey()])
        ->assertOk()
        ->assertSee('Resumo da conta física')
        ->assertSee($account->optionLabel())
        ->assertSee('1 aplicação bancária cadastrada nesta conta.')
        ->assertSee('Aplicação bancária pendente')
        ->assertSee('dark:text-gray-300');

    $fund->update(['bank_application_id' => $application->id]);

    Livewire::test(EditFund::class, ['record' => $fund->getRouteKey()])
        ->assertOk()
        ->assertDontSee('Aplicação bancária pendente');
});

it('keeps legacy fund editing without a global account', function () {
    $this->actingAs(makeUnifiedFundAdminUser());

    $fund = Fund::factory()->create([
        'trade_name' => 'Fundo Legado',
        'agency' => '1234-5',
        'account' => '12345-6',
    ]);

    Livewire::test(EditFund::class, ['record' => $fund->getRouteKey()])
        ->fillForm([
            'trade_name' => 'Fundo Legado Renomeado',
            'bank_account_id' => null,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $fund->refresh();

    expect($fund->trade_name)->toBe('Fundo Legado Renomeado')
        ->and($fund->bank_account_id)->toBeNull()
        ->and($fund->bank_application_id)->toBeNull()
        ->and($fund->agency)->toBe('1234-5')
        ->and($fund->account)->toBe('12345-6');
});

it('preserves the fund-level conta azul uuid when unrelated fields change', function () {
    $this->actingAs(makeUnifiedFundAdminUser());

    $uuid = '33333333-3333-3333-3333-333333333333';
    $account = BankAccount::factory()->create([
        'conta_azul_account_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
    ]);
    $fund = Fund::factory()->create([
        'bank_id' => $account->bank_id,
        'agency' => $account->formattedAgency(),
        'account' => $account->formattedAccount(),
        'bank_account_id' => $account->id,
        'conta_azul_account_id' => $uuid,
    ]);

    Livewire::test(EditFund::class, ['record' => $fund->getRouteKey()])
        ->fillForm([
            'trade_name' => 'Novo nome fantasia',
            'minimum_balance' => '5.000,00',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($fund->fresh()->conta_azul_account_id)->toBe($uuid)
        ->and($account->fresh()->conta_azul_account_id)->toBe('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');
});

it('rejects a conta azul uuid already used by another fund', function () {
    $this->actingAs(makeUnifiedFundAdminUser());

    $uuid = '44444444-4444-4444-4444-444444444444';
    Fund::factory()->create(['conta_azul_account_id' => $uuid]);

    [$emission, $fundType, $fundName, $classification] = makeUnifiedFundClassification();

    Livewire::test(CreateFund::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'fund_type_id' => $fundType->id,
            'fund_name_id' => $fundName->id,
            'fund_application_id' => $classification->id,
            'bank_account_id' => BankAccount::factory()->create()->id,
            'conta_azul_account_id' => $uuid,
            'balance' => '10.000,00',
        ])
        ->call('create')
        ->assertHasFormErrors(['conta_azul_account_id' => 'unique']);

    expect(Fund::query()->count())->toBe(1);
});

it('keeps the operational sync reading fund-level uuids into each fund emission', function () {
    Http::preventStrayRequests();

    ContaAzulToken::query()->create([
        'access_token' => 'fake-access-token',
        'refresh_token' => 'fake-refresh-token',
        'expires_at' => now()->addDay(),
    ]);

    $account = BankAccount::factory()->create();
    $first = Fund::factory()->create([
        'bank_account_id' => $account->id,
        'conta_azul_account_id' => '55555555-5555-5555-5555-555555555555',
    ]);
    $second = Fund::factory()->create([
        'emission_id' => Emission::factory()->create()->id,
        'bank_account_id' => $account->id,
        'conta_azul_account_id' => '66666666-6666-6666-6666-666666666666',
    ]);

    Http::fake([
        'https://api-v2.contaazul.com/*' => function ($request) {
            $accountId = $request->data()['ids_contas_financeiras'] ?? 'unknown';

            return Http::response([
                'itens' => [[
                    'id' => 'bill-for-'.$accountId,
                    'status' => 'PENDING',
                    'total' => 100.00,
                    'pago' => 0.0,
                    'data_vencimento' => now()->addMonth()->toDateString(),
                    'categorias' => [['nome' => 'AUDITORIA']],
                ]],
                'itens_totais' => 1,
            ], 200);
        },
    ]);

    app(SyncContaAzulExpensesJob::class)->handle(app(ContaAzulClient::class));

    $firstHistory = ExpenseHistory::query()->where('conta_azul_bill_id', 'bill-for-55555555-5555-5555-5555-555555555555')->first();
    $secondHistory = ExpenseHistory::query()->where('conta_azul_bill_id', 'bill-for-66666666-6666-6666-6666-666666666666')->first();

    expect($firstHistory)->not->toBeNull()
        ->and($secondHistory)->not->toBeNull()
        ->and($firstHistory->expense->emission_id)->toBe($first->emission_id)
        ->and($secondHistory->expense->emission_id)->toBe($second->emission_id)
        ->and($firstHistory->expense->category)->toBe('Auditoria')
        ->and(ContaAzulImportedBill::query()->count())->toBe(0)
        ->and(ContaAzulImportBatch::query()->count())->toBe(0);
});

it('denies fund access without funds permissions', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    expect(FundResource::canViewAny())->toBeFalse()
        ->and(FundResource::canCreate())->toBeFalse();

    Livewire::test(ListFunds::class)->assertForbidden();
    Livewire::test(CreateFund::class)->assertForbidden();
});

it('leaves balances, allocations, guarantees, expenses and staging untouched during registration', function () {
    $this->actingAs(makeUnifiedFundAdminUser());

    [$emission, $fundType, $fundName, $classification] = makeUnifiedFundClassification();
    $account = BankAccount::factory()->create();
    $application = BankApplication::factory()->create(['bank_account_id' => $account->id]);

    Livewire::test(CreateFund::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'fund_type_id' => $fundType->id,
            'fund_name_id' => $fundName->id,
            'fund_application_id' => $classification->id,
            'bank_account_id' => $account->id,
            'bank_application_id' => $application->id,
            'balance' => '25.000,00',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $fund = Fund::query()->first();

    Livewire::test(EditFund::class, ['record' => $fund->getRouteKey()])
        ->fillForm(['trade_name' => 'Ajuste operacional'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($fund->fresh()->balance)->toBe('25000.00')
        ->and(FundBalanceHistory::query()->count())->toBe(0)
        ->and(FundAllocationPeriod::query()->count())->toBe(0)
        ->and(Guarantee::query()->count())->toBe(0)
        ->and(Expense::query()->count())->toBe(0)
        ->and(ExpenseHistory::query()->count())->toBe(0)
        ->and(ContaAzulImportedBill::query()->count())->toBe(0)
        ->and(ContaAzulImportBatch::query()->count())->toBe(0);
});

it('narrows allocation periods by operation through the emission filter', function () {
    $this->actingAs(makeUnifiedFundAdminUser());

    $visible = FundAllocationPeriod::factory()->create();
    $hidden = FundAllocationPeriod::factory()->create();
    Fund::factory()->create([
        'emission_id' => Emission::factory()->create(['name' => 'Operação Visível'])->id,
        'bank_application_id' => $visible->bank_application_id,
    ]);
    Fund::factory()->create([
        'emission_id' => Emission::factory()->create(['name' => 'Operação Oculta'])->id,
        'bank_application_id' => $hidden->bank_application_id,
    ]);

    $emissionId = $visible->bankApplication->funds()->first()->emission_id;

    Livewire::test(ListFundAllocations::class)
        ->filterTable('emission_id', $emissionId)
        ->assertCanSeeTableRecords([$visible])
        ->assertCanNotSeeTableRecords([$hidden]);
});

/**
 * @return array{Emission, FundType, FundName, FundClassification}
 */
function makeUnifiedFundClassification(?string $emissionName = null): array
{
    $emission = Emission::factory()->create($emissionName !== null ? ['name' => $emissionName] : []);
    $fundType = FundType::factory()->create();
    $fundName = FundName::factory()->create(['fund_type_id' => $fundType->id]);
    $classification = FundClassification::factory()->create();

    return [$emission, $fundType, $fundName, $classification];
}

function makeUnifiedFundAdminUser(): User
{
    $user = User::factory()->withTwoFactor()->create([
        'email' => fake()->unique()->safeEmail(),
    ]);
    $user->assignRole('admin');

    return $user;
}
