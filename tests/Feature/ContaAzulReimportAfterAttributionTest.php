<?php

use App\Models\ContaAzulBillAttribution;
use App\Models\ContaAzulImportedBill;
use App\Models\Emission;
use App\Models\Expense;
use App\Models\ExpenseHistory;
use App\Models\Fund;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\ContaAzulReconciliationFixture as Staging;
use Tests\Support\FundAllocationFixture;

/*
 * Phase 3B: reimport after attribution.
 *
 * A changed external record never silently invalidates an authorized
 * decision: staging mirrors the source, attribution-relevant changes raise
 * renewed review with previous values preserved, and harmless metadata
 * changes update quietly. Operational Expenses are never touched.
 */

pest()->group('parity');

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function reimportAttributedBill(array $recordOverrides = []): array
{
    $admin = FundAllocationFixture::userWithRole('admin');
    $actor = FundAllocationFixture::userWithPermissions(['conta-azul-imports.attribute', 'emissions.view']);
    $account = Staging::account();
    $emission = Emission::factory()->create();
    Fund::factory()->create(['bank_account_id' => $account->id, 'emission_id' => $emission->id]);

    $record = Staging::bill(array_merge(['id' => 'bill-reimport-'.fake()->uuid()], $recordOverrides));

    Staging::ingest($admin, $account, [Staging::page([$record])]);

    $bill = ContaAzulImportedBill::query()->where('conta_azul_bill_id', $record['id'])->firstOrFail();

    $attribution = Staging::attributionService()->attribute(
        $actor, $bill, $emission, 'Atribuição antes da mudança externa.', $bill->payload_fingerprint,
    );

    return [$admin, $actor, $account, $emission, $bill->refresh(), $attribution, $record];
}

it('flags renewed review when the external amount changes', function () {
    [$admin, $actor, $account, $emission, $bill, $attribution, $record] = reimportAttributedBill();

    $snapshot = Staging::operationalSnapshot();

    Staging::ingest($admin, $account, [
        Staging::page([array_merge($record, ['total' => 2500.00, 'nao_pago' => 2500.00])]),
    ], ['from' => '2026-11-01', 'to' => '2026-11-30']);

    $bill->refresh();

    expect($bill->total_amount)->toBe('2500.00')
        ->and($bill->import_status)->toBe(ContaAzulImportedBill::IMPORT_UPDATED)
        ->and($bill->attributed_emission_id)->toBe($emission->id)
        ->and($bill->reconciliation_status)->toBe(ContaAzulImportedBill::RECONCILIATION_ATTRIBUTED)
        ->and($bill->needs_re_review)->toBeTrue()
        ->and($bill->re_review_reason)->toContain('Valor nominal')
        ->and($bill->attribution_source_fingerprint)->toBe($attribution->source_fingerprint)
        ->and($bill->previous_payload_fingerprint)->toBe($attribution->source_fingerprint)
        ->and(ContaAzulBillAttribution::query()->where('conta_azul_imported_bill_id', $bill->id)->count())->toBe(1)
        ->and(Staging::operationalSnapshot())->toBe($snapshot);

    $event = Activity::query()->where('description', 'bill_source_changed')->firstOrFail();
    $properties = $event->properties->toArray();

    expect($properties['changed_fields'])->toContain('Valor nominal')
        ->and($properties['previous_values']['total_amount'])->toBe('1200.00')
        ->and($properties['attributed_emission_id'])->toBe($emission->id);
});

it('flags renewed review when the external category changes', function () {
    [$admin, $actor, $account, $emission, $bill, $attribution, $record] = reimportAttributedBill();

    Staging::ingest($admin, $account, [
        Staging::page([array_merge($record, ['categorias' => [['id' => 'cat-uuid-audit', 'nome' => 'AUDITORIA']]])]),
    ], ['from' => '2026-11-01', 'to' => '2026-11-30']);

    $bill->refresh();

    expect($bill->mapped_category)->toBe('Auditoria')
        ->and($bill->needs_re_review)->toBeTrue()
        ->and($bill->re_review_reason)->toContain('Categoria mapeada')
        ->and($bill->attributed_emission_id)->toBe($emission->id)
        ->and($bill->attribution_source_fingerprint)->toBe($attribution->source_fingerprint);
});

it('flags renewed review when the external payment status changes', function () {
    [$admin, $actor, $account, $emission, $bill, $attribution, $record] = reimportAttributedBill();

    Staging::ingest($admin, $account, [
        Staging::page([array_merge($record, [
            'status' => 'PAID',
            'pago' => 1200.00,
            'nao_pago' => 0,
            'data_pagamento' => '2026-10-25',
        ])]),
    ], ['from' => '2026-11-01', 'to' => '2026-11-30']);

    $bill->refresh();

    expect($bill->external_status)->toBe('PAID')
        ->and($bill->needs_re_review)->toBeTrue()
        ->and($bill->re_review_reason)->toContain('Status externo')
        ->and($bill->attributed_emission_id)->toBe($emission->id);
});

it('updates quietly when only harmless metadata changes', function () {
    [$admin, $actor, $account, $emission, $bill, $attribution, $record] = reimportAttributedBill();

    Staging::ingest($admin, $account, [
        Staging::page([array_merge($record, [
            'descricao' => 'DESPESA OPERACIONAL AJUSTADA',
            'data_alteracao' => '2026-11-05T12:00:00.000000',
        ])]),
    ], ['from' => '2026-11-01', 'to' => '2026-11-30']);

    $bill->refresh();

    expect($bill->description)->toBe('DESPESA OPERACIONAL AJUSTADA')
        ->and($bill->import_status)->toBe(ContaAzulImportedBill::IMPORT_UPDATED)
        ->and($bill->needs_re_review)->toBeFalse()
        ->and($bill->re_review_reason)->toBeNull()
        ->and($bill->attributed_emission_id)->toBe($emission->id)
        ->and(Activity::query()->where('description', 'bill_source_changed')->count())->toBe(0);
});

it('clears renewed review when a correction re-confirms the decision', function () {
    [$admin, $actor, $account, $emission, $bill, $attribution, $record] = reimportAttributedBill();
    $corrector = FundAllocationFixture::userWithPermissions(['conta-azul-imports.correct', 'emissions.view']);

    Staging::ingest($admin, $account, [
        Staging::page([array_merge($record, ['total' => 1300.00, 'nao_pago' => 1300.00])]),
    ], ['from' => '2026-11-01', 'to' => '2026-11-30']);

    $bill->refresh();

    expect($bill->needs_re_review)->toBeTrue();

    Staging::attributionService()->correct(
        $corrector, $bill, $emission, 'Valor conferido contra o novo extrato; decisão mantida.', $bill->payload_fingerprint,
        $bill->latestAttribution()->getKey(),
    );

    $bill->refresh();

    expect($bill->needs_re_review)->toBeFalse()
        ->and($bill->re_review_reason)->toBeNull()
        ->and($bill->attribution_source_fingerprint)->toBe($bill->payload_fingerprint)
        ->and($bill->attributed_emission_id)->toBe($emission->id)
        ->and(ContaAzulBillAttribution::query()->where('conta_azul_imported_bill_id', $bill->id)->count())->toBe(2);
});

it('detects bills already represented in the legacy workflow', function () {
    [$admin, $actor, $account, $emission, $bill] = reimportAttributedBill();

    $legacyExpense = Expense::factory()->create(['emission_id' => $emission->id]);
    $legacyExpense->histories()->create([
        'conta_azul_bill_id' => $bill->conta_azul_bill_id,
        'amount' => 1200.00,
        'due_date' => '2026-10-20',
    ]);

    $history = $bill->refresh()->findLegacyHistory();

    expect($history)->not->toBeNull()
        ->and($history->expense_id)->toBe($legacyExpense->id);
});

it('surfaces disagreements between staged and legacy emission attributions', function () {
    [$admin, $actor, $account, $emission, $bill] = reimportAttributedBill();

    $legacyEmission = Emission::factory()->create();
    $legacyExpense = Expense::factory()->create(['emission_id' => $legacyEmission->id]);
    $legacyExpense->histories()->create([
        'conta_azul_bill_id' => $bill->conta_azul_bill_id,
        'amount' => 1200.00,
        'due_date' => '2026-10-20',
    ]);

    $disagreement = Staging::attributionService()->legacyDisagreement($bill->refresh(), $emission->id);

    expect($disagreement)->toBe([
        'legacy_expense_id' => $legacyExpense->id,
        'legacy_emission_id' => $legacyEmission->id,
    ]);

    // Attributing to the legacy emission is agreement, not disagreement.
    Fund::factory()->create(['bank_account_id' => $account->id, 'emission_id' => $legacyEmission->id]);
    $corrector = FundAllocationFixture::userWithPermissions(['conta-azul-imports.correct', 'emissions.view']);
    $latest = $bill->latestAttribution();

    Staging::attributionService()->correct(
        $corrector, $bill, $legacyEmission, 'Alinhamento com o fluxo legado após conferência.', $bill->payload_fingerprint,
        $latest->getKey(),
    );

    expect(Staging::attributionService()->legacyDisagreement($bill->refresh(), $legacyEmission->id))->toBeNull()
        ->and(ExpenseHistory::query()->where('conta_azul_bill_id', $bill->conta_azul_bill_id)->count())->toBe(1);
});

it('never duplicates or rewrites legacy histories during reimport', function () {
    [$admin, $actor, $account, $emission, $bill, $attribution, $record] = reimportAttributedBill();

    $legacyExpense = Expense::factory()->create(['emission_id' => $emission->id]);
    $history = $legacyExpense->histories()->create([
        'conta_azul_bill_id' => $bill->conta_azul_bill_id,
        'amount' => '1200.00',
        'due_date' => '2026-10-20',
    ]);
    $before = $history->toArray();

    Staging::ingest($admin, $account, [
        Staging::page([array_merge($record, ['total' => 9999.00, 'nao_pago' => 9999.00])]),
    ], ['from' => '2026-11-01', 'to' => '2026-11-30']);

    expect($bill->refresh()->total_amount)->toBe('9999.00')
        ->and(ExpenseHistory::query()->where('conta_azul_bill_id', $bill->conta_azul_bill_id)->count())->toBe(1);

    $history->refresh();

    expect($history->amount)->toBe($before['amount'])
        ->and($history->updated_at->equalTo($history->created_at))->toBeTrue();
});
