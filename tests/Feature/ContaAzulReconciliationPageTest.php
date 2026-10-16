<?php

use App\Filament\Resources\ContaAzulImportBatches\ContaAzulImportBatchResource;
use App\Filament\Resources\ContaAzulImportBatches\Pages\ListContaAzulImportBatches;
use App\Filament\Resources\ContaAzulImportBatches\Pages\ViewContaAzulImportBatch;
use App\Filament\Resources\ContaAzulImportedBills\ContaAzulImportedBillResource;
use App\Filament\Resources\ContaAzulImportedBills\Pages\ListContaAzulImportedBills;
use App\Filament\Resources\ContaAzulImportedBills\Pages\ViewContaAzulImportedBill;
use App\Models\ContaAzulImportBatch;
use App\Models\ContaAzulImportedBill;
use App\Models\Emission;
use App\Models\Expense;
use App\Models\Fund;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\ContaAzulReconciliationFixture as Staging;
use Tests\Support\FundAllocationFixture;

/*
 * Phase 3B: reconciliation administration.
 *
 * Page access, listing, filtering, visibility scoping, evidence display
 * and the governed actions as operated through the Filament UI. Service
 * authorization itself is covered at the domain layer; these tests prove
 * the UI neither leaks nor bypasses it.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function reconciliationScenario(): array
{
    $admin = FundAllocationFixture::userWithRole('admin');
    $accountA = Staging::account();
    $accountB = Staging::account();
    $emission = Emission::factory()->create(['name' => 'Emissão Conciliável']);
    Fund::factory()->create(['bank_account_id' => $accountA->id, 'emission_id' => $emission->id]);

    $batchA = Staging::ingest($admin, $accountA, [Staging::page([
        Staging::bill(['id' => 'bill-page-1', 'descricao' => 'ENERGIA ELÉTRICA MATRIZ']),
        Staging::bill(['id' => 'bill-page-2', 'descricao' => 'AUDITORIA EXTERNA']),
    ])]);
    $batchB = Staging::ingest($admin, $accountB, [Staging::page([
        Staging::bill(['id' => 'bill-page-3', 'descricao' => 'CONDOMÍNIO FILIAL']),
    ])]);
    $billB = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-page-3')->firstOrFail();
    Staging::attributionService()->markInsufficient($admin, $billB, 'Conta sem vínculo com emissão.');

    return [$admin, $accountA, $accountB, $emission, $batchA, $batchB];
}

it('denies reconciliation access without the view permission', function () {
    reconciliationScenario();
    $stranger = FundAllocationFixture::userWithPermissions(['emissions.view']);

    $this->actingAs($stranger);

    expect(ContaAzulImportedBillResource::canViewAny())->toBeFalse()
        ->and(ContaAzulImportBatchResource::canViewAny())->toBeFalse();

    Livewire::test(ListContaAzulImportedBills::class)->assertForbidden();
    Livewire::test(ListContaAzulImportBatches::class)->assertForbidden();
});

it('lists staged bills and filters by account and status', function () {
    [$admin, $accountA, $accountB] = reconciliationScenario();
    $viewer = FundAllocationFixture::userWithPermissions(['conta-azul-imports.view']);

    $this->actingAs($viewer);

    $one = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-page-1')->firstOrFail();
    $three = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-page-3')->firstOrFail();

    Livewire::test(ListContaAzulImportedBills::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$one, $three])
        ->filterTable('bank_account_id', $accountB->id)
        ->assertCanSeeTableRecords([$three])
        ->assertCanNotSeeTableRecords([$one]);

    Livewire::test(ListContaAzulImportedBills::class)
        ->filterTable('reconciliation_status', [ContaAzulImportedBill::RECONCILIATION_PENDING])
        ->assertCanSeeTableRecords([$one])
        ->assertCanNotSeeTableRecords([$three]);
});

it('hides emission names from viewers without emission access', function () {
    [$admin, $accountA, $accountB, $emission] = reconciliationScenario();
    $attributor = FundAllocationFixture::userWithPermissions(['conta-azul-imports.attribute', 'emissions.view']);
    $bill = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-page-1')->firstOrFail();

    Staging::attributionService()->attribute(
        $attributor, $bill, $emission, 'Atribuição para teste de visibilidade.', $bill->payload_fingerprint,
    );

    $viewer = FundAllocationFixture::userWithPermissions(['conta-azul-imports.view']);

    $this->actingAs($viewer);

    Livewire::test(ListContaAzulImportedBills::class)
        ->assertSuccessful()
        ->assertDontSee('Emissão Conciliável');

    $privileged = FundAllocationFixture::userWithPermissions(['conta-azul-imports.view', 'emissions.view']);

    $this->actingAs($privileged);

    Livewire::test(ListContaAzulImportedBills::class)->assertSee('Emissão Conciliável');
});

it('presents masked evidence without the raw payload', function () {
    [$admin] = reconciliationScenario();
    $viewer = FundAllocationFixture::userWithPermissions(['conta-azul-imports.view']);

    $this->actingAs($viewer);

    $bill = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-page-1')->firstOrFail();

    Livewire::test(ViewContaAzulImportedBill::class, ['record' => $bill->getKey()])
        ->assertSuccessful()
        ->assertSee('ENERGIA ELÉTRICA MATRIZ')
        ->assertSee($bill->maskedBillId())
        ->assertSee($bill->payload_fingerprint)
        ->assertDontSee($bill->conta_azul_bill_id)
        ->assertDontSee('data_criacao');
});

it('attributes a bill through the review action', function () {
    [$admin, $accountA, $accountB, $emission] = reconciliationScenario();
    $actor = FundAllocationFixture::userWithPermissions([
        'conta-azul-imports.view', 'conta-azul-imports.attribute', 'emissions.view',
    ]);

    $this->actingAs($actor);

    $bill = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-page-2')->firstOrFail();

    Livewire::test(ViewContaAzulImportedBill::class, ['record' => $bill->getKey()])
        ->assertSuccessful()
        ->callAction('attribute', data: [
            'emission_id' => $emission->id,
            'reason' => 'Conferido contra o extrato da conta.',
            'expected_fingerprint' => $bill->payload_fingerprint,
        ])
        ->assertHasNoActionErrors();

    expect($bill->refresh()->attributed_emission_id)->toBe($emission->id);
});

it('refuses the review action without attribution authority', function () {
    [$admin, $accountA, $accountB, $emission] = reconciliationScenario();
    $viewer = FundAllocationFixture::userWithPermissions(['conta-azul-imports.view', 'emissions.view']);

    $this->actingAs($viewer);

    $bill = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-page-2')->firstOrFail();

    Livewire::test(ViewContaAzulImportedBill::class, ['record' => $bill->getKey()])
        ->assertSuccessful()
        ->assertActionHidden('attribute')
        ->assertActionHidden('mark_ready')
        ->assertActionHidden('correct');

    expect($bill->refresh()->isAttributed())->toBeFalse();
});

it('flags legacy representation on the detail page', function () {
    [$admin, $accountA, $accountB, $emission] = reconciliationScenario();
    $viewer = FundAllocationFixture::userWithPermissions(['conta-azul-imports.view']);

    $bill = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-page-1')->firstOrFail();
    $expense = Expense::factory()->create(['emission_id' => $emission->id]);
    $expense->histories()->create([
        'conta_azul_bill_id' => $bill->conta_azul_bill_id,
        'amount' => 1200.00,
        'due_date' => '2026-10-20',
    ]);

    $this->actingAs($viewer);

    Livewire::test(ViewContaAzulImportedBill::class, ['record' => $bill->getKey()])
        ->assertSuccessful()
        ->assertSee('Já registrado no fluxo legado');
});

it('recovers a stale batch through the batch page', function () {
    [$admin, $accountA] = reconciliationScenario();
    $actor = FundAllocationFixture::userWithPermissions(['conta-azul-imports.view', 'conta-azul-imports.recover']);

    $victim = ContaAzulImportBatch::factory()->create([
        'bank_account_id' => $accountA->id,
        'conta_azul_account_id' => $accountA->conta_azul_account_id,
        'status' => ContaAzulImportBatch::STATUS_RUNNING,
        'started_at' => now()->subHours(3),
        'last_heartbeat_at' => now()->subHours(2),
    ]);

    $this->actingAs($actor);

    Livewire::test(ListContaAzulImportBatches::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$victim]);

    Livewire::test(ViewContaAzulImportBatch::class, ['record' => $victim->getKey()])
        ->assertSuccessful()
        ->callAction('recover', data: ['reason' => 'Recuperação pela interface administrativa.'])
        ->assertHasNoActionErrors();

    expect($victim->refresh()->status)->toBe(ContaAzulImportBatch::STATUS_ABANDONED);
});
