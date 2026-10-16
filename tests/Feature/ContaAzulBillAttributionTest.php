<?php

use App\Exceptions\Funds\ContaAzulReconciliationException;
use App\Models\ContaAzulBillAttribution;
use App\Models\ContaAzulImportBatch;
use App\Models\ContaAzulImportedBill;
use App\Models\Emission;
use App\Models\Fund;
use App\Models\User;
use App\Support\BankIdentifierMasker;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\ContaAzulReconciliationFixture as Staging;
use Tests\Support\FundAllocationFixture;

/*
 * Phase 3B: governed Emission attribution for staged Conta Azul bills.
 *
 * Attribution is an explicit, authorized, auditable staging decision: it
 * sets the bill's single effective Emission and appends to the decision
 * history. It never creates or rewrites operational Expenses, never reads
 * allocation percentages, and never infers ownership from shared accounts.
 */

pest()->group('parity');

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function attributionLinkFund(array $overrides = []): Fund
{
    return Fund::factory()->create($overrides);
}

it('leaves newly imported bills unattributed and pending', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = Staging::account();
    $emission = Emission::factory()->create();
    attributionLinkFund(['bank_account_id' => $account->id, 'emission_id' => $emission->id]);

    $batch = Staging::ingest($admin, $account, [Staging::page([Staging::bill()])]);

    $bill = ContaAzulImportedBill::query()->where('conta_azul_import_batch_id', $batch->id)->firstOrFail();

    expect($batch->status)->toBe(ContaAzulImportBatch::STATUS_COMPLETED)
        ->and($bill->reconciliation_status)->toBe(ContaAzulImportedBill::RECONCILIATION_PENDING)
        ->and($bill->attributed_emission_id)->toBeNull()
        ->and($bill->isAttributed())->toBeFalse()
        ->and(ContaAzulBillAttribution::query()->count())->toBe(0);
});

it('never attributes a bill merely because its category matches', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = Staging::account();

    $batch = Staging::ingest($admin, $account, [Staging::page([Staging::bill()])]);

    $bill = ContaAzulImportedBill::query()->where('conta_azul_import_batch_id', $batch->id)->firstOrFail();

    expect($bill->mapped_category)->toBe('Sistema')
        ->and($bill->reconciliation_status)->toBe(ContaAzulImportedBill::RECONCILIATION_PENDING)
        ->and($bill->attributed_emission_id)->toBeNull();

    Staging::attributionService()->markReady($admin, $bill);

    expect($bill->refresh()->reconciliation_status)->toBe(ContaAzulImportedBill::RECONCILIATION_READY_FOR_REVIEW)
        ->and($bill->attributed_emission_id)->toBeNull();
});

it('flags bills missing critical information as a reviewable exception', function () {
    $reviewer = FundAllocationFixture::userWithPermissions(['conta-azul-imports.attribute']);
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = Staging::account();

    $batch = Staging::ingest($admin, $account, [Staging::page([Staging::bill()])]);
    $bill = ContaAzulImportedBill::query()->where('conta_azul_import_batch_id', $batch->id)->firstOrFail();

    expect(fn () => Staging::attributionService()->markInsufficient($reviewer, $bill, 'curto'))
        ->toThrow(ContaAzulReconciliationException::class);

    $bill = Staging::attributionService()->markInsufficient($reviewer, $bill, 'Fornecedor e categoria ausentes na fonte.');

    expect($bill->reconciliation_status)->toBe(ContaAzulImportedBill::RECONCILIATION_INSUFFICIENT_INFORMATION)
        ->and($bill->reconciliation_note)->toContain('Fornecedor')
        ->and($bill->attributed_emission_id)->toBeNull();

    $emission = Emission::factory()->create();
    attributionLinkFund(['bank_account_id' => $account->id, 'emission_id' => $emission->id]);

    expect(fn () => Staging::attributionService()->attribute(
        FundAllocationFixture::userWithPermissions(['conta-azul-imports.attribute', 'emissions.view']),
        $bill->refresh(),
        $emission,
        'Tentativa após insuficiência registrada.',
        $bill->payload_fingerprint,
    ))->toThrow(ContaAzulReconciliationException::class);
});

it('attributes a reviewed bill to an authorized linked emission', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $actor = FundAllocationFixture::userWithPermissions(['conta-azul-imports.attribute', 'emissions.view']);
    $account = Staging::account();
    $emission = Emission::factory()->create();
    attributionLinkFund(['bank_account_id' => $account->id, 'emission_id' => $emission->id]);

    $batch = Staging::ingest($admin, $account, [Staging::page([Staging::bill()])]);
    $bill = ContaAzulImportedBill::query()->where('conta_azul_import_batch_id', $batch->id)->firstOrFail();
    $snapshot = Staging::operationalSnapshot();

    $attribution = Staging::attributionService()->attribute(
        $actor,
        $bill,
        $emission,
        'Despesa operacional confirmada no extrato da conta.',
        $bill->payload_fingerprint,
    );

    $bill->refresh();

    expect($attribution->action)->toBe(ContaAzulBillAttribution::ACTION_ATTRIBUTED)
        ->and($attribution->to_emission_id)->toBe($emission->id)
        ->and($attribution->from_emission_id)->toBeNull()
        ->and($attribution->decided_by_user_id)->toBe($actor->id)
        ->and($attribution->is_exceptional)->toBeFalse()
        ->and($bill->isAttributed())->toBeTrue()
        ->and($bill->attributed_emission_id)->toBe($emission->id)
        ->and($bill->attributed_by_user_id)->toBe($actor->id)
        ->and($bill->attributed_at)->not->toBeNull()
        ->and($bill->attribution_source_fingerprint)->toBe($bill->payload_fingerprint)
        ->and($bill->reconciliation_status)->toBe(ContaAzulImportedBill::RECONCILIATION_ATTRIBUTED)
        ->and(Staging::operationalSnapshot())->toBe($snapshot);

    $event = Activity::query()->where('log_name', 'conta_azul_imports')->where('description', 'bill_attributed')->firstOrFail();
    $properties = $event->properties->toArray();

    expect($properties['to_emission_id'])->toBe($emission->id)
        ->and($properties['conta_azul_bill_id'])->toBe(BankIdentifierMasker::maskContaAzulId($bill->conta_azul_bill_id))
        ->and($properties['conta_azul_bill_id'])->not->toBe($bill->conta_azul_bill_id);
});

it('rejects attribution without the attribution permission', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $viewer = FundAllocationFixture::userWithPermissions(['conta-azul-imports.view', 'emissions.view']);
    $account = Staging::account();
    $emission = Emission::factory()->create();
    attributionLinkFund(['bank_account_id' => $account->id, 'emission_id' => $emission->id]);

    $batch = Staging::ingest($admin, $account, [Staging::page([Staging::bill()])]);
    $bill = ContaAzulImportedBill::query()->where('conta_azul_import_batch_id', $batch->id)->firstOrFail();

    expect(fn () => Staging::attributionService()->attribute(
        $viewer,
        $bill,
        $emission,
        'Tentativa sem permissão de atribuição.',
        $bill->payload_fingerprint,
    ))->toThrow(ContaAzulReconciliationException::class);

    expect($bill->refresh()->isAttributed())->toBeFalse()
        ->and(ContaAzulBillAttribution::query()->count())->toBe(0);
});

it('rejects attribution to an emission the actor cannot see', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $actor = FundAllocationFixture::userWithPermissions(['conta-azul-imports.attribute']);
    $account = Staging::account();
    $emission = Emission::factory()->create();
    attributionLinkFund(['bank_account_id' => $account->id, 'emission_id' => $emission->id]);

    $batch = Staging::ingest($admin, $account, [Staging::page([Staging::bill()])]);
    $bill = ContaAzulImportedBill::query()->where('conta_azul_import_batch_id', $batch->id)->firstOrFail();

    expect(fn () => Staging::attributionService()->attribute(
        $actor,
        $bill,
        $emission,
        'Tentativa contra emissão inacessível.',
        $bill->payload_fingerprint,
    ))->toThrow(ContaAzulReconciliationException::class);

    expect(Staging::attributionService()->candidateEmissions($actor, $bill))->toBeEmpty();
});

it('keeps same-account multi-emission attributions independent', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $actor = FundAllocationFixture::userWithPermissions(['conta-azul-imports.attribute', 'emissions.view']);
    $account = Staging::account();
    $emissionA = Emission::factory()->create(['name' => 'Emissão Alfa']);
    $emissionB = Emission::factory()->create(['name' => 'Emissão Beta']);
    attributionLinkFund(['bank_account_id' => $account->id, 'emission_id' => $emissionA->id]);
    attributionLinkFund(['bank_account_id' => $account->id, 'emission_id' => $emissionB->id]);

    $batch = Staging::ingest($admin, $account, [Staging::page([Staging::bill(), Staging::bill()])]);
    $bills = ContaAzulImportedBill::query()->where('conta_azul_import_batch_id', $batch->id)->orderBy('id')->get();

    $candidates = Staging::attributionService()->candidateEmissions($actor, $bills[0]);

    expect($candidates->pluck('id')->sort()->values()->all())->toBe(collect([$emissionA->id, $emissionB->id])->sort()->values()->all());

    Staging::attributionService()->attribute($actor, $bills[0], $emissionA, 'Título da operação Alfa.', $bills[0]->payload_fingerprint);
    Staging::attributionService()->attribute($actor, $bills[1], $emissionB, 'Título da operação Beta.', $bills[1]->payload_fingerprint);

    expect($bills[0]->refresh()->attributed_emission_id)->toBe($emissionA->id)
        ->and($bills[1]->refresh()->attributed_emission_id)->toBe($emissionB->id);
});

it('requires an attribution explanation', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $actor = FundAllocationFixture::userWithPermissions(['conta-azul-imports.attribute', 'emissions.view']);
    $account = Staging::account();
    $emission = Emission::factory()->create();
    attributionLinkFund(['bank_account_id' => $account->id, 'emission_id' => $emission->id]);

    $batch = Staging::ingest($admin, $account, [Staging::page([Staging::bill()])]);
    $bill = ContaAzulImportedBill::query()->where('conta_azul_import_batch_id', $batch->id)->firstOrFail();

    expect(fn () => Staging::attributionService()->attribute($actor, $bill, $emission, 'curto', $bill->payload_fingerprint))
        ->toThrow(ContaAzulReconciliationException::class);

    expect($bill->refresh()->isAttributed())->toBeFalse();
});

it('governs exceptional attribution to an unlinked emission', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $actor = FundAllocationFixture::userWithPermissions(['conta-azul-imports.attribute', 'emissions.view', 'emissions.update']);
    $account = Staging::account();
    $linked = Emission::factory()->create();
    $outsider = Emission::factory()->create();
    attributionLinkFund(['bank_account_id' => $account->id, 'emission_id' => $linked->id]);

    $batch = Staging::ingest($admin, $account, [Staging::page([Staging::bill()])]);
    $bill = ContaAzulImportedBill::query()->where('conta_azul_import_batch_id', $batch->id)->firstOrFail();

    expect(Staging::attributionService()->candidateEmissions($actor, $bill)->pluck('id')->all())->toBe([$linked->id]);

    // No confirmation: refused even with authority.
    expect(fn () => Staging::attributionService()->attribute(
        $actor, $bill, $outsider, 'Justificativa longa o suficiente para a exceção.', $bill->payload_fingerprint,
    ))->toThrow(ContaAzulReconciliationException::class);

    // Short justification: refused even when confirmed.
    expect(fn () => Staging::attributionService()->attribute(
        $actor, $bill, $outsider, 'Curta demais.', $bill->payload_fingerprint, ['exceptional_confirmed' => true],
    ))->toThrow(ContaAzulReconciliationException::class);

    $attribution = Staging::attributionService()->attribute(
        $actor, $bill, $outsider, 'Rateio manual definido em comitê fora da conta vinculada.', $bill->payload_fingerprint,
        ['exceptional_confirmed' => true],
    );

    expect($attribution->is_exceptional)->toBeTrue()
        ->and($bill->refresh()->attributed_emission_id)->toBe($outsider->id);
});

it('requires emission update authority for exceptional attribution', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $actor = FundAllocationFixture::userWithPermissions(['conta-azul-imports.attribute', 'emissions.view']);
    $account = Staging::account();
    $linked = Emission::factory()->create();
    $outsider = Emission::factory()->create();
    attributionLinkFund(['bank_account_id' => $account->id, 'emission_id' => $linked->id]);

    $batch = Staging::ingest($admin, $account, [Staging::page([Staging::bill()])]);
    $bill = ContaAzulImportedBill::query()->where('conta_azul_import_batch_id', $batch->id)->firstOrFail();

    expect(fn () => Staging::attributionService()->attribute(
        $actor, $bill, $outsider, 'Justificativa longa mas sem autoridade de edição.', $bill->payload_fingerprint,
        ['exceptional_confirmed' => true],
    ))->toThrow(ContaAzulReconciliationException::class);
});

it('corrects an attribution while preserving the original decision', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $attributor = FundAllocationFixture::userWithPermissions(['conta-azul-imports.attribute', 'emissions.view']);
    $corrector = FundAllocationFixture::userWithPermissions(['conta-azul-imports.correct', 'emissions.view']);
    $account = Staging::account();
    $emissionA = Emission::factory()->create();
    $emissionB = Emission::factory()->create();
    attributionLinkFund(['bank_account_id' => $account->id, 'emission_id' => $emissionA->id]);
    attributionLinkFund(['bank_account_id' => $account->id, 'emission_id' => $emissionB->id]);

    $batch = Staging::ingest($admin, $account, [Staging::page([Staging::bill()])]);
    $bill = ContaAzulImportedBill::query()->where('conta_azul_import_batch_id', $batch->id)->firstOrFail();
    $snapshot = Staging::operationalSnapshot();

    $first = Staging::attributionService()->attribute(
        $attributor, $bill, $emissionA, 'Atribuição inicial após conferência.', $bill->payload_fingerprint,
    );

    expect(fn () => Staging::attributionService()->correct(
        $corrector, $bill->refresh(), $emissionB, 'curta', $bill->payload_fingerprint, $first->id,
    ))->toThrow(ContaAzulReconciliationException::class);

    $correction = Staging::attributionService()->correct(
        $corrector, $bill->refresh(), $emissionB, 'Emissão corrigida após revisão do borderô.', $bill->payload_fingerprint, $first->id,
    );

    $history = ContaAzulBillAttribution::query()->where('conta_azul_imported_bill_id', $bill->id)->orderBy('id')->get();

    expect($history)->toHaveCount(2)
        ->and($history[0]->action)->toBe(ContaAzulBillAttribution::ACTION_ATTRIBUTED)
        ->and($history[0]->to_emission_id)->toBe($emissionA->id)
        ->and($history[0]->decided_by_user_id)->toBe($attributor->id)
        ->and($correction->action)->toBe(ContaAzulBillAttribution::ACTION_CORRECTED)
        ->and($correction->from_emission_id)->toBe($emissionA->id)
        ->and($correction->to_emission_id)->toBe($emissionB->id)
        ->and($correction->decided_by_user_id)->toBe($corrector->id)
        ->and($bill->refresh()->attributed_emission_id)->toBe($emissionB->id)
        ->and(Staging::operationalSnapshot())->toBe($snapshot);

    expect(Activity::query()->where('description', 'bill_attribution_corrected')->count())->toBe(1);
});

it('resolves duplicate attribution submissions to the existing event', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $actor = FundAllocationFixture::userWithPermissions(['conta-azul-imports.attribute', 'emissions.view']);
    $account = Staging::account();
    $emission = Emission::factory()->create();
    attributionLinkFund(['bank_account_id' => $account->id, 'emission_id' => $emission->id]);

    $batch = Staging::ingest($admin, $account, [Staging::page([Staging::bill()])]);
    $bill = ContaAzulImportedBill::query()->where('conta_azul_import_batch_id', $batch->id)->firstOrFail();

    $first = Staging::attributionService()->attribute(
        $actor, $bill, $emission, 'Decisão registrada uma única vez.', $bill->payload_fingerprint,
    );
    $second = Staging::attributionService()->attribute(
        $actor, $bill->refresh(), $emission, 'Decisão registrada uma única vez.', $bill->payload_fingerprint,
    );

    expect($second->getKey())->toBe($first->getKey())
        ->and(ContaAzulBillAttribution::query()->where('conta_azul_imported_bill_id', $bill->id)->count())->toBe(1)
        ->and(Activity::query()->where('description', 'bill_attributed')->count())->toBe(1);
});

it('refuses a second conflicting attribution for the same bill', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $actorA = FundAllocationFixture::userWithPermissions(['conta-azul-imports.attribute', 'emissions.view']);
    $actorB = FundAllocationFixture::userWithPermissions(['conta-azul-imports.attribute', 'emissions.view']);
    $account = Staging::account();
    $emissionA = Emission::factory()->create();
    $emissionB = Emission::factory()->create();
    attributionLinkFund(['bank_account_id' => $account->id, 'emission_id' => $emissionA->id]);
    attributionLinkFund(['bank_account_id' => $account->id, 'emission_id' => $emissionB->id]);

    $batch = Staging::ingest($admin, $account, [Staging::page([Staging::bill()])]);
    $bill = ContaAzulImportedBill::query()->where('conta_azul_import_batch_id', $batch->id)->firstOrFail();

    Staging::attributionService()->attribute($actorA, $bill, $emissionA, 'Primeira decisão vence.', $bill->payload_fingerprint);

    expect(fn () => Staging::attributionService()->attribute(
        $actorB, $bill->refresh(), $emissionB, 'Segunda decisão conflitante.', $bill->payload_fingerprint,
    ))->toThrow(ContaAzulReconciliationException::class);

    expect($bill->refresh()->attributed_emission_id)->toBe($emissionA->id)
        ->and(ContaAzulBillAttribution::query()->where('conta_azul_imported_bill_id', $bill->id)->count())->toBe(1);
});

it('refuses decisions against a stale source version', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $actor = FundAllocationFixture::userWithPermissions(['conta-azul-imports.attribute', 'emissions.view']);
    $account = Staging::account();
    $emission = Emission::factory()->create();
    attributionLinkFund(['bank_account_id' => $account->id, 'emission_id' => $emission->id]);

    $batch = Staging::ingest($admin, $account, [Staging::page([Staging::bill()])]);
    $bill = ContaAzulImportedBill::query()->where('conta_azul_import_batch_id', $batch->id)->firstOrFail();

    expect(fn () => Staging::attributionService()->attribute(
        $actor, $bill, $emission, 'Decisão contra evidência antiga.', str_repeat('0', 64),
    ))->toThrow(ContaAzulReconciliationException::class);

    expect($bill->refresh()->isAttributed())->toBeFalse();
});

it('refuses corrections that lost a race to a newer decision', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $actor = FundAllocationFixture::userWithPermissions(['conta-azul-imports.attribute', 'conta-azul-imports.correct', 'emissions.view']);
    $account = Staging::account();
    $emissionA = Emission::factory()->create();
    $emissionB = Emission::factory()->create();
    $emissionC = Emission::factory()->create();
    attributionLinkFund(['bank_account_id' => $account->id, 'emission_id' => $emissionA->id]);
    attributionLinkFund(['bank_account_id' => $account->id, 'emission_id' => $emissionB->id]);
    attributionLinkFund(['bank_account_id' => $account->id, 'emission_id' => $emissionC->id]);

    $batch = Staging::ingest($admin, $account, [Staging::page([Staging::bill()])]);
    $bill = ContaAzulImportedBill::query()->where('conta_azul_import_batch_id', $batch->id)->firstOrFail();

    $first = Staging::attributionService()->attribute($actor, $bill, $emissionA, 'Decisão inicial.', $bill->payload_fingerprint);
    Staging::attributionService()->correct(
        $actor, $bill->refresh(), $emissionB, 'Correção mais recente vence.', $bill->payload_fingerprint, $first->id,
    );

    expect(fn () => Staging::attributionService()->correct(
        $actor, $bill->refresh(), $emissionC, 'Correção obsoleta contra decisão antiga.', $bill->payload_fingerprint, $first->id,
    ))->toThrow(ContaAzulReconciliationException::class);

    expect($bill->refresh()->attributed_emission_id)->toBe($emissionB->id);
});

it('holds bills from partial imports out of attribution until an exception justifies it', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $actor = FundAllocationFixture::userWithPermissions(['conta-azul-imports.attribute', 'emissions.view']);
    $account = Staging::account();
    $emission = Emission::factory()->create();
    attributionLinkFund(['bank_account_id' => $account->id, 'emission_id' => $emission->id]);

    $batch = Staging::ingest($admin, $account, [
        Staging::page([Staging::bill(['id' => 'bill-partial-hold'])], 5),
    ]);

    expect($batch->status)->toBe(ContaAzulImportBatch::STATUS_PARTIAL);

    $bill = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-partial-hold')->firstOrFail();

    expect($bill->hasVerifiedContent())->toBeFalse();

    expect(fn () => Staging::attributionService()->attribute(
        $actor, $bill, $emission, 'Tentativa sem importação completa.', $bill->payload_fingerprint,
    ))->toThrow(ContaAzulReconciliationException::class);

    expect(fn () => Staging::attributionService()->attribute(
        $actor, $bill->refresh(), $emission, 'Tentativa com exceção sem motivo.', $bill->payload_fingerprint,
        ['allow_incomplete' => true],
    ))->toThrow(ContaAzulReconciliationException::class);

    Staging::attributionService()->attribute(
        $actor, $bill->refresh(), $emission, 'Exceção aprovada: período parcial suficiente.', $bill->payload_fingerprint,
        ['allow_incomplete' => true, 'incomplete_exception_reason' => 'Fechamento urgente com fonte parcial auditada.'],
    );

    $event = Activity::query()->where('description', 'bill_attributed')->firstOrFail();

    expect($bill->refresh()->isAttributed())->toBeTrue()
        ->and($event->properties->toArray()['incomplete_exception_reason'])->toContain('Fechamento urgente');
});

it('re-verifies a partial bill once a completed import observes it', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $actor = FundAllocationFixture::userWithPermissions(['conta-azul-imports.attribute', 'emissions.view']);
    $account = Staging::account();
    $emission = Emission::factory()->create();
    attributionLinkFund(['bank_account_id' => $account->id, 'emission_id' => $emission->id]);

    $record = Staging::bill(['id' => 'bill-reverify']);

    Staging::ingest($admin, $account, [Staging::page([$record], 5)], ['from' => '2026-10-01', 'to' => '2026-10-15']);

    $bill = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-reverify')->firstOrFail();

    expect($bill->hasVerifiedContent())->toBeFalse();

    $retry = Staging::ingest($admin, $account, [Staging::page([$record])], ['from' => '2026-10-16', 'to' => '2026-10-31']);

    expect($retry->status)->toBe(ContaAzulImportBatch::STATUS_COMPLETED)
        ->and($bill->refresh()->hasVerifiedContent())->toBeTrue();

    Staging::attributionService()->attribute($actor, $bill, $emission, 'Atribuição após verificação completa.', $bill->payload_fingerprint);

    expect($bill->refresh()->isAttributed())->toBeTrue();
});

it('keeps failed-import error bills visible but out of attribution', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $actor = FundAllocationFixture::userWithPermissions(['conta-azul-imports.attribute', 'emissions.view']);
    $account = Staging::account();
    $emission = Emission::factory()->create();
    attributionLinkFund(['bank_account_id' => $account->id, 'emission_id' => $emission->id]);

    $batch = Staging::ingest($admin, $account, [Staging::page([Staging::bill(['id' => 'bill-broken', 'total' => null])])]);
    $bill = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-broken')->firstOrFail();

    expect($bill->import_status)->toBe(ContaAzulImportedBill::IMPORT_ERROR)
        ->and($bill->hasVerifiedContent())->toBeFalse();

    expect(fn () => Staging::attributionService()->attribute(
        $actor, $bill, $emission, 'Tentativa contra título com erro.', $bill->payload_fingerprint,
    ))->toThrow(ContaAzulReconciliationException::class);

    $bill = Staging::attributionService()->markInsufficient($actor, $bill, 'Total ausente na fonte; aguardar correção.');

    expect($bill->reconciliation_status)->toBe(ContaAzulImportedBill::RECONCILIATION_INSUFFICIENT_INFORMATION);
});

it('ignores allocation percentages and writes nothing to allocations or guarantees', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $actor = FundAllocationFixture::userWithPermissions(['conta-azul-imports.attribute', 'emissions.view']);
    $account = Staging::account();
    $emission = Emission::factory()->create();
    $application = FundAllocationFixture::application();
    FundAllocationFixture::linkedFund($application, $emission);
    attributionLinkFund(['bank_account_id' => $account->id, 'emission_id' => $emission->id]);

    $batch = Staging::ingest($admin, $account, [Staging::page([Staging::bill()])]);
    $bill = ContaAzulImportedBill::query()->where('conta_azul_import_batch_id', $batch->id)->firstOrFail();

    $allocationsBefore = [
        'periods' => DB::table('fund_allocation_periods')->count(),
        'revisions' => DB::table('fund_allocation_revisions')->count(),
        'lines' => DB::table('fund_allocation_lines')->count(),
        'guarantees' => DB::table('guarantees')->count(),
    ];

    Staging::attributionService()->attribute($actor, $bill, $emission, 'Decisão independente de alocações.', $bill->payload_fingerprint);

    expect(DB::table('fund_allocation_periods')->count())->toBe($allocationsBefore['periods'])
        ->and(DB::table('fund_allocation_revisions')->count())->toBe($allocationsBefore['revisions'])
        ->and(DB::table('fund_allocation_lines')->count())->toBe($allocationsBefore['lines'])
        ->and(DB::table('guarantees')->count())->toBe($allocationsBefore['guarantees'])
        ->and($bill->refresh()->isAttributed())->toBeTrue();
});

it('requires the correction permission for corrections', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $attributor = FundAllocationFixture::userWithPermissions(['conta-azul-imports.attribute', 'emissions.view']);
    $account = Staging::account();
    $emissionA = Emission::factory()->create();
    $emissionB = Emission::factory()->create();
    attributionLinkFund(['bank_account_id' => $account->id, 'emission_id' => $emissionA->id]);
    attributionLinkFund(['bank_account_id' => $account->id, 'emission_id' => $emissionB->id]);

    $batch = Staging::ingest($admin, $account, [Staging::page([Staging::bill()])]);
    $bill = ContaAzulImportedBill::query()->where('conta_azul_import_batch_id', $batch->id)->firstOrFail();

    $first = Staging::attributionService()->attribute($attributor, $bill, $emissionA, 'Decisão inicial.', $bill->payload_fingerprint);

    expect(fn () => Staging::attributionService()->correct(
        $attributor, $bill->refresh(), $emissionB, 'Correção sem permissão de correção.', $bill->payload_fingerprint, $first->id,
    ))->toThrow(ContaAzulReconciliationException::class);

    expect($bill->refresh()->attributed_emission_id)->toBe($emissionA->id);
});

it('enforces authorization at the service layer, outside any UI', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $stranger = User::factory()->create();
    $account = Staging::account();

    $batch = Staging::ingest($admin, $account, [Staging::page([Staging::bill()])]);
    $bill = ContaAzulImportedBill::query()->where('conta_azul_import_batch_id', $batch->id)->firstOrFail();

    expect(fn () => Staging::attributionService()->markReady($stranger, $bill))
        ->toThrow(ContaAzulReconciliationException::class);

    expect(fn () => Staging::attributionService()->markInsufficient($stranger, $bill, 'Nota sem permissão alguma.'))
        ->toThrow(ContaAzulReconciliationException::class);

    expect($bill->refresh()->reconciliation_status)->toBe(ContaAzulImportedBill::RECONCILIATION_PENDING);
});
