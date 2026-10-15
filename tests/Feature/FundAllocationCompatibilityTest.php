<?php

use App\Filament\Resources\Funds\Pages\CreateFund;
use App\Filament\Resources\Funds\Pages\EditFund;
use App\Jobs\SyncContaAzulExpensesJob;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\BankApplication;
use App\Models\ContaAzulToken;
use App\Models\Emission;
use App\Models\Expense;
use App\Models\ExpenseHistory;
use App\Models\Fund;
use App\Models\FundBalanceHistory;
use App\Services\ContaAzulClient;
use App\Services\Guarantees\EmissionOperationalDataset;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FundAllocationFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

// ── Scenario 25: legacy financial data preserved ─────────────────────────────

it('never touches legacy fund balances or histories during allocation work', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    [$emission] = FundAllocationFixture::classification();
    $fund = FundAllocationFixture::linkedFund($application, $emission, overrides: [
        'balance' => '77777.77',
        'minimum_balance' => '1000.00',
        'balance_updated_at' => '2026-09-01 10:00:00',
    ]);
    $history = FundBalanceHistory::query()->create([
        'fund_id' => $fund->id,
        'date' => '2026-08-31',
        'balance' => '70000.00',
    ]);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '100000.00');
    $draft = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '60000.00'],
    ]);
    $service->approve($checker, $service->submitForReview($maker, $draft), 'legacy-safe-1');

    $correction = $service->proposeCorrection($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '65000.00'],
    ], 'Ajuste.');
    $service->approve($checker, $service->submitForReview($maker, $correction), 'legacy-safe-2');

    expect($fund->refresh()->balance)->toBe('77777.77')
        ->and($fund->minimum_balance)->toBe('1000.00')
        ->and($fund->balance_updated_at->toDateTimeString())->toBe('2026-09-01 10:00:00')
        ->and($history->refresh()->balance)->toBe('70000.00')
        ->and(FundBalanceHistory::query()->where('fund_id', $fund->id)->count())->toBe(1);
});

// ── Scenario 26: guarantee calculations unchanged ────────────────────────────

it('leaves guarantee balance reads identical before and after allocations', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    [$emission] = FundAllocationFixture::classification();
    $fund = FundAllocationFixture::linkedFund($application, $emission, overrides: [
        'balance' => '50000.00',
        'balance_updated_at' => '2026-09-05 12:00:00',
    ]);
    FundBalanceHistory::query()->create([
        'fund_id' => $fund->id,
        'date' => '2026-08-31',
        'balance' => '48000.00',
    ]);
    $service = FundAllocationFixture::service();

    $before = new EmissionOperationalDataset($emission->refresh());
    $currentBefore = $before->fundBalanceForMonth($fund->refresh(), '2026-09-01');
    $historyBefore = $before->fundBalanceForMonth($fund, '2026-08-01');

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '999999.00');
    $draft = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '1.00'],
    ]);
    $service->approve($checker, $service->submitForReview($maker, $draft), 'guarantee-safe-1');

    $after = new EmissionOperationalDataset($emission->refresh()->load('funds.balanceHistories'));

    expect($currentBefore)->toBe(50000.00)
        ->and($historyBefore)->toBe(48000.00)
        ->and($after->fundBalanceForMonth($fund->refresh(), '2026-09-01'))->toBe($currentBefore)
        ->and($after->fundBalanceForMonth($fund, '2026-08-01'))->toBe($historyBefore);
});

// ── Scenario 27: Conta Azul behavior unchanged ───────────────────────────────

it('syncs Conta Azul expenses identically regardless of allocation activity', function () {
    config()->set('conta-azul.category_map', ['Taxa de Gestão' => 'Gestão']);

    ContaAzulToken::query()->create([
        'access_token' => 'fake-access-token',
        'refresh_token' => 'fake-refresh-token',
        'expires_at' => now()->addDay(),
    ]);

    $application = FundAllocationFixture::application();
    [$emission] = FundAllocationFixture::classification();
    $fund = FundAllocationFixture::linkedFund($application, $emission, overrides: [
        'conta_azul_account_id' => 'fund-acc-uuid-alloc',
    ]);

    $bill = [
        'id' => 'bill-uuid-alloc-1',
        'status' => 'PENDING',
        'total' => 8103.55,
        'data_vencimento' => '2026-09-10',
        'pago' => 0,
        'nao_pago' => 8103.55,
        'categorias' => [['id' => 'cat-1', 'nome' => 'Taxa de Gestão']],
    ];

    Http::fake([
        'https://api-v2.contaazul.com/v1/financeiro/eventos-financeiros/contas-a-pagar/buscar*' => Http::response([
            'itens' => [$bill],
            'itens_totais' => 1,
        ]),
    ]);

    (new SyncContaAzulExpensesJob)->handle(app(ContaAzulClient::class));

    expect(Expense::query()->count())->toBe(1)
        ->and(ExpenseHistory::query()->where('conta_azul_bill_id', 'bill-uuid-alloc-1')->count())->toBe(1);

    // Full allocation lifecycle on the same application and fund.
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $service = FundAllocationFixture::service();
    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '100000.00');
    $draft = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '60000.00'],
    ]);
    $service->approve($checker, $service->submitForReview($maker, $draft), 'contaazul-safe-1');

    (new SyncContaAzulExpensesJob)->handle(app(ContaAzulClient::class));

    $expense = Expense::query()->firstOrFail();
    $history = ExpenseHistory::query()->where('conta_azul_bill_id', 'bill-uuid-alloc-1')->firstOrFail();

    expect(Expense::query()->count())->toBe(1)
        ->and(ExpenseHistory::query()->count())->toBe(1)
        ->and((float) $expense->amount)->toBe(8103.55)
        ->and($expense->category)->toBe('Gestão')
        ->and((int) $expense->emission_id)->toBe($emission->id)
        ->and($fund->refresh()->conta_azul_account_id)->toBe('fund-acc-uuid-alloc')
        ->and((float) $history->amount)->toBe(8103.55);
});

// ── Scenario 28: Fund CRUD compatibility ─────────────────────────────────────

it('keeps fund create, update and delete working', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $this->actingAs($admin);

    [$emission, $fundType, $fundName, $classification] = FundAllocationFixture::classification();

    $fund = Fund::query()->create([
        'emission_id' => $emission->id,
        'fund_type_id' => $fundType->id,
        'fund_name_id' => $fundName->id,
        'fund_application_id' => $classification->id,
        'bank_id' => Bank::factory()->create()->id,
        'agency' => '1234-5',
        'account' => '99999-9',
        'balance' => '10.00',
    ]);

    expect($fund->exists)->toBeTrue();

    $fund->update(['balance' => '20.00', 'minimum_balance' => '5.00']);

    expect($fund->refresh()->balance)->toBe('20.00');

    // Linked funds still create through the model with consistent references.
    $application = FundAllocationFixture::application();
    $linked = FundAllocationFixture::linkedFund($application);

    expect($linked->bank_application_id)->toBe($application->id);

    $fund->delete();

    expect(Fund::query()->whereKey($fund->id)->exists())->toBeFalse();
});

// ── Scenario 29: shared-indicator accuracy ───────────────────────────────────

it('marks an application shared only above one distinct emission', function () {
    $application = FundAllocationFixture::application();
    [$emissionA] = FundAllocationFixture::classification();

    expect($application->isShared())->toBeFalse()
        ->and($application->distinctEmissionCount())->toBe(0);

    // Two funds of the same emission: one distinct emission, not shared.
    FundAllocationFixture::linkedFund($application, $emissionA);
    FundAllocationFixture::linkedFund($application, $emissionA);

    expect($application->distinctEmissionCount())->toBe(1)
        ->and($application->isShared())->toBeFalse();

    [$emissionB] = FundAllocationFixture::classification();
    FundAllocationFixture::linkedFund($application, $emissionB);

    expect($application->distinctEmissionCount())->toBe(2)
        ->and($application->isShared())->toBeTrue();

    // The listing scope reports the same COUNT(DISTINCT emission_id).
    $scoped = BankApplication::query()->withSharedEmissionCount()->findOrFail($application->id);

    expect($scoped->distinctEmissionCount())->toBe(2);

    // Funds sharing only the parent account never count as shared application.
    $siblingApplication = BankApplication::factory()->create([
        'bank_account_id' => $application->bank_account_id,
    ]);
    [$emissionC] = FundAllocationFixture::classification();
    FundAllocationFixture::linkedFund($siblingApplication, $emissionC);

    expect($application->distinctEmissionCount())->toBe(2)
        ->and($siblingApplication->distinctEmissionCount())->toBe(1)
        ->and($siblingApplication->isShared())->toBeFalse();
});

// ── Scenario 30: legacy unique constraint ────────────────────────────────────

it('allows the same identity with distinct bank applications', function () {
    $account = BankAccount::factory()->create();
    $productA = BankApplication::factory()->create([
        'bank_account_id' => $account->id,
        'codigo_produto' => '00001',
    ]);
    $productB = BankApplication::factory()->create([
        'bank_account_id' => $account->id,
        'codigo_produto' => '00002',
    ]);
    [$emission, $fundType, $fundName, $classification] = FundAllocationFixture::classification();

    $attributes = [
        'emission_id' => $emission->id,
        'fund_type_id' => $fundType->id,
        'fund_name_id' => $fundName->id,
        'fund_application_id' => $classification->id,
        'bank_id' => $account->bank_id,
        'agency' => $account->formattedAgency(),
        'account' => $account->formattedAccount(),
        'bank_account_id' => $account->id,
    ];

    $first = Fund::query()->create(array_merge($attributes, ['bank_application_id' => $productA->id]));
    $second = Fund::query()->create(array_merge($attributes, ['bank_application_id' => $productB->id]));

    expect($first->exists)->toBeTrue()
        ->and($second->exists)->toBeTrue()
        ->and((int) $first->refresh()->bank_application_identity)->toBe($productA->id)
        ->and((int) $second->refresh()->bank_application_identity)->toBe($productB->id);
});

it('still rejects true duplicates under the application-aware identity', function () {
    [$emission, $fundType, $fundName, $classification] = FundAllocationFixture::classification();

    // Legacy rows (no linked application) keep the exact old identity.
    $legacy = [
        'emission_id' => $emission->id,
        'fund_type_id' => $fundType->id,
        'fund_name_id' => $fundName->id,
        'fund_application_id' => $classification->id,
        'bank_id' => Bank::factory()->create()->id,
        'agency' => '1111-1',
        'account' => '22222-2',
        'bank_account_id' => null,
        'bank_application_id' => null,
    ];

    Fund::query()->create($legacy);

    expect(fn () => Fund::query()->create($legacy))->toThrow(QueryException::class);

    // Same application twice is still a duplicate identity.
    $application = FundAllocationFixture::application();
    $linked = FundAllocationFixture::linkedFund($application, $emission, $fundType, $fundName, $classification);

    expect(fn () => Fund::query()->create([
        'emission_id' => $linked->emission_id,
        'fund_type_id' => $linked->fund_type_id,
        'fund_name_id' => $linked->fund_name_id,
        'fund_application_id' => $linked->fund_application_id,
        'bank_id' => $linked->bank_id,
        'agency' => $linked->agency,
        'account' => $linked->account,
        'bank_account_id' => $linked->bank_account_id,
        'bank_application_id' => $linked->bank_application_id,
    ]))->toThrow(QueryException::class);
});

it('registers the second application fund through the fund form', function () {
    $this->actingAs(makeAdminUser());

    [$emission, $fundType, $fundName, $classification] = FundAllocationFixture::classification();
    $account = BankAccount::factory()->create([
        'agency_number' => '0123',
        'agency_digit' => '4',
        'account_number' => '0012345',
        'account_digit' => '6',
    ]);
    $productA = BankApplication::factory()->create([
        'bank_account_id' => $account->id,
        'codigo_produto' => '00042',
    ]);
    $productB = BankApplication::factory()->create([
        'bank_account_id' => $account->id,
        'codigo_produto' => '00043',
    ]);

    $form = [
        'emission_id' => $emission->id,
        'fund_type_id' => $fundType->id,
        'fund_name_id' => $fundName->id,
        'fund_application_id' => $classification->id,
        'bank_account_id' => $account->id,
        'balance' => '10.000,00',
    ];

    Livewire::test(CreateFund::class)
        ->fillForm(array_merge($form, ['bank_application_id' => $productA->id]))
        ->call('create')
        ->assertHasNoFormErrors();

    // Same operation, classification and derived account string — the
    // distinct bank application makes this a valid second registration.
    Livewire::test(CreateFund::class)
        ->fillForm(array_merge($form, ['bank_application_id' => $productB->id]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Fund::query()->where('emission_id', $emission->id)->count())->toBe(2);
});

it('shows the legacy-balance wording on a shared application fund', function () {
    $this->actingAs(makeAdminUser());

    $application = FundAllocationFixture::application();
    [$emissionA] = FundAllocationFixture::classification();
    [$emissionB] = FundAllocationFixture::classification();
    FundAllocationFixture::linkedFund($application, $emissionA);
    $fund = FundAllocationFixture::linkedFund($application, $emissionB);

    $this->get(EditFund::getUrl(['record' => $fund->id]))
        ->assertSuccessful()
        ->assertSee('valor manual legado')
        ->assertSee('não é uma parcela de alocação aprovada', false);
});
