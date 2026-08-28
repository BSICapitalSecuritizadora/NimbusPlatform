<?php

use App\Domain\PuCalculator\Enums\PuBaselineReadinessStatus;
use App\Domain\PuCalculator\Enums\PuBaselineRequirementStatus;
use App\Domain\PuCalculator\Services\PuBaselineReadinessService;
use App\Enums\LegalDocumentType;
use App\Enums\LegalInstrumentDocumentRole;
use App\Enums\LegalInstrumentFieldKey;
use App\Enums\LegalInstrumentFieldStatus;
use App\Enums\LegalInstrumentType;
use App\Models\Document;
use App\Models\Emission;
use App\Models\EmissionPuBaselineEvidence;
use App\Models\LegalInstrument;
use App\Models\LegalInstrumentDocument;
use App\Models\LegalInstrumentField;
use App\Services\LegalInstruments\AltoBellevueContractEvidenceBackfill;
use App\Services\LegalInstruments\InstrumentChangeReviewService;
use App\Services\LegalInstruments\InstrumentPositionResolver;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('produces a complete dry run without changing contractual evidence', function () {
    $fixture = altoBellevueEvidenceFixture();
    $before = LegalInstrumentField::query()->count();

    $report = app(AltoBellevueContractEvidenceBackfill::class)->execute();

    expect($report['dry_run'])->toBeTrue()
        ->and($report['rows'])->toHaveCount(21)
        ->and($report['inserts'])->toBe(20)
        ->and($report['preserved'])->toBe(1)
        ->and($report['manual_review_required'])->toHaveCount(1)
        ->and($report['infrastructure_actions'])->toContain('link_first_amendment_to_governing_instrument')
        ->and(LegalInstrumentField::query()->count())->toBe($before)
        ->and($fixture['misclassified_issue_date']->refresh()->status)->toBe(LegalInstrumentFieldStatus::Confirmed);

    $rows = collect($report['rows'])->keyBy('field_key');

    expect($rows->keys()->all())->toContain(
        LegalInstrumentFieldKey::Indexer->value,
        LegalInstrumentFieldKey::Spread->value,
        LegalInstrumentFieldKey::DayCountRule->value,
        LegalInstrumentFieldKey::BusinessDayDefinition->value,
        LegalInstrumentFieldKey::CalendarCode->value,
        LegalInstrumentFieldKey::PaymentSchedule->value,
        LegalInstrumentFieldKey::Amortization->value,
        LegalInstrumentFieldKey::FirstCouponPreIntegralizationPremiumEnabled->value,
    )->and($rows->every(fn (array $row): bool => $row['document_id'] === $fixture['term_document']->id
        && $row['instrument_id'] === $fixture['term']->id
        && filled($row['clause'])
        && filled($row['page'])
        && filled($row['excerpt'])
        && filled($row['value_type'])
        && filled($row['evidence_level'])
        && is_float($row['confidence_score'])))->toBeTrue()
        ->and($rows->get(LegalInstrumentFieldKey::MaturityDate->value)['action'])->toBe('preserve_confirmed')
        ->and($rows->get(LegalInstrumentFieldKey::MaturityDate->value)['proposed_status'])->toBe('confirmed')
        ->and($rows->get(LegalInstrumentFieldKey::Indexer->value)['canonical_value'])->toBe('CDI')
        ->and($rows->get(LegalInstrumentFieldKey::CalendarCode->value)['evidence_level'])->toBe('inferred');
});

it('writes only idempotent pending proposals and preserves every backing instrument field', function () {
    $fixture = altoBellevueEvidenceFixture();

    $first = app(AltoBellevueContractEvidenceBackfill::class)->execute(write: true);
    $second = app(AltoBellevueContractEvidenceBackfill::class)->execute(write: true);

    expect($first['dry_run'])->toBeFalse()
        ->and($first['inserts'])->toBe(20)
        ->and($second['inserts'])->toBe(0)
        ->and($fixture['term']->fields()->pendingReview()->count())->toBe(20)
        ->and($fixture['ccb_spread']->refresh()->status)->toBe(LegalInstrumentFieldStatus::Confirmed)
        ->and($fixture['ccb_spread']->value_numeric)->toBe(0.075)
        ->and($fixture['ccb_amortization']->refresh()->value)->toBe('ccb_contractual_installments')
        ->and($fixture['misclassified_issue_date']->refresh()->status)->toBe(LegalInstrumentFieldStatus::Confirmed)
        ->and($fixture['term']->documents()->where('document_id', $fixture['amendment_document']->id)->count())->toBe(1)
        ->and($fixture['legacy_amendment_instrument']->documents()->where('document_id', $fixture['amendment_document']->id)->count())->toBe(1)
        ->and($fixture['emission']->refresh()->integralized_quantity)->toBe(4000)
        ->and(EmissionPuBaselineEvidence::query()->whereBelongsTo($fixture['emission'])->exists())->toBeFalse();

    $pending = $fixture['term']->fields()->pendingReview()->get();

    expect($pending->every(fn (LegalInstrumentField $field): bool => $field->document_id === $fixture['term_document']->id
        && $field->legal_instrument_document_id === $fixture['term_instrument_document']->id
        && $field->status === LegalInstrumentFieldStatus::PendingReview
        && $field->reviewed_by === null
        && $field->reviewed_at === null))->toBeTrue()
        ->and($pending->pluck('field_key')->contains(LegalInstrumentFieldKey::Indexer))->toBeTrue();
});

it('uses the securitization term after review without treating CCB terms as conflicts', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $fixture = altoBellevueEvidenceFixture();
    app(AltoBellevueContractEvidenceBackfill::class)->execute(write: true);

    $beforeReview = app(PuBaselineReadinessService::class)->evaluate(
        $fixture['emission']->fresh(),
        CarbonImmutable::parse('2026-08-27'),
    );

    expect($beforeReview->status)->toBe(PuBaselineReadinessStatus::Blocked)
        ->and($beforeReview->requirement('contract_spread')->status)->toBe(PuBaselineRequirementStatus::Blocking);

    app(InstrumentChangeReviewService::class)->confirmMany(
        $fixture['term']->fields()->pendingReview()->get(),
        makeAdminUser(),
        'Conferido contra o Termo de Securitização e seu Anexo II.',
    );

    $report = app(PuBaselineReadinessService::class)->evaluate(
        $fixture['emission']->fresh(),
        CarbonImmutable::parse('2026-08-27'),
    );

    expect($report->status)->toBe(PuBaselineReadinessStatus::Blocked)
        ->and($report->candidateConfiguration)->toMatchArray([
            'indexer' => 'CDI',
            'index_percentage' => '100.00000000',
            'spread_rate' => '6.00000000',
            'business_day_basis' => 252,
            'calendar_code' => 'BR_NATIONAL_HOLIDAYS',
            'index_rate_lookup_mode' => 'business_day_lag_exact',
            'index_rate_lag_business_days' => -5,
            'curve_start_date' => 'PENDING',
            'curve_end_date' => '2031-05-08',
            'initial_unit_value' => '1000.0000000000000000',
            'first_coupon_pre_integralization_premium_enabled' => true,
            'first_coupon_pre_integralization_business_days' => 2,
            'first_coupon_pre_integralization_apply_index_factor' => true,
            'first_coupon_pre_integralization_apply_spread_factor' => true,
        ])
        ->and($report->eventDiagnostics['schedule'])->toMatchArray([
            'first_interest_payment_date' => '2026-06-08',
            'interest_payment_frequency' => 'monthly',
            'amortization' => 'bullet',
            'payment_convention' => 'following_business_day',
        ])
        ->and($report->requirement('contract_issue_date')->status)->toBe(PuBaselineRequirementStatus::Satisfied)
        ->and($report->requirement('contract_spread')->status)->toBe(PuBaselineRequirementStatus::Satisfied)
        ->and($report->requirement('contract_bullet')->status)->toBe(PuBaselineRequirementStatus::Satisfied)
        ->and($report->requirement('contract_opening_premium')->status)->toBe(PuBaselineRequirementStatus::Satisfied)
        ->and($report->requirement('first_integralization_date')->status)->toBe(PuBaselineRequirementStatus::Blocking)
        ->and($report->requirement('calendar_administrative_confirmation')->isSatisfied())->toBeFalse()
        ->and($report->requirement('index_source_operational_approval')->isSatisfied())->toBeFalse();

    $resolver = app(InstrumentPositionResolver::class);
    $termPosition = $resolver->fieldsAsOf($fixture['term']->fresh(), '2026-08-27');
    $ccbPosition = $resolver->fieldsAsOf($fixture['ccb']->fresh(), '2026-08-27');

    expect($termPosition->get(LegalInstrumentFieldKey::IssueDate->value)?->current->value_date?->toDateString())->toBe('2026-05-08')
        ->and($termPosition->get(LegalInstrumentFieldKey::Indexer->value)?->current->value)->toBe('CDI')
        ->and($termPosition->get(LegalInstrumentFieldKey::Indexer->value)?->current->excerpt)->toContain('Taxa DI')
        ->and($termPosition->get(LegalInstrumentFieldKey::Spread->value)?->current->value_numeric)->toBe(0.06)
        ->and($termPosition->get(LegalInstrumentFieldKey::Amortization->value)?->current->value)->toBe('bullet')
        ->and($ccbPosition->get(LegalInstrumentFieldKey::Spread->value)?->current->value_numeric)->toBe(0.075)
        ->and($ccbPosition->get(LegalInstrumentFieldKey::Amortization->value)?->current->value)->toBe('ccb_contractual_installments')
        ->and($fixture['ccb_indexer']->refresh()->value)->toBe('Taxa DI')
        ->and($fixture['misclassified_issue_date']->refresh()->value_date?->toDateString())->toBe('2026-04-30')
        ->and(EmissionPuBaselineEvidence::query()->whereBelongsTo($fixture['emission'])->exists())->toBeFalse();
});

/**
 * @return array{
 *     emission: Emission,
 *     term: LegalInstrument,
 *     term_document: Document,
 *     term_instrument_document: LegalInstrumentDocument,
 *     amendment_document: Document,
 *     legacy_amendment_instrument: LegalInstrument,
 *     ccb: LegalInstrument,
 *     ccb_spread: LegalInstrumentField,
 *     ccb_indexer: LegalInstrumentField,
 *     ccb_amortization: LegalInstrumentField,
 *     misclassified_issue_date: LegalInstrumentField
 * }
 */
function altoBellevueEvidenceFixture(): array
{
    $emission = Emission::factory()->create([
        'name' => 'CRI Alto Bellevue',
        'type' => 'CRI',
        'if_code' => AltoBellevueContractEvidenceBackfill::IF_CODE,
        'isin_code' => AltoBellevueContractEvidenceBackfill::ISIN_CODE,
        'issue_date' => '2026-05-08',
        'maturity_date' => '2031-05-08',
        'issued_quantity' => 5000,
        'integralized_quantity' => 4000,
        'issued_price' => '1000.00',
    ]);

    $termDocument = Document::factory()->create(['title' => 'Termo de Securitização Alto Bellevue']);
    $emission->documents()->attach($termDocument, [
        'legal_document_type' => LegalDocumentType::SecuritizationTerm->value,
        'document_date' => '2026-05-08',
    ]);
    $termDocument = $emission->documents()->findOrFail($termDocument->id);

    $term = LegalInstrument::factory()
        ->ofType(LegalInstrumentType::SecuritizationTerm)
        ->create(['emission_id' => $emission->id]);
    $termInstrumentDocument = LegalInstrumentDocument::factory()
        ->original('2026-05-08')
        ->processed()
        ->create([
            'legal_instrument_id' => $term->id,
            'document_id' => $termDocument->id,
        ]);

    $amendmentDocument = Document::factory()->create(['title' => 'Primeiro Aditivo']);
    $emission->documents()->attach($amendmentDocument, [
        'legal_document_type' => LegalDocumentType::TermAmendment->value,
        'document_date' => '2026-05-13',
    ]);
    $amendmentDocument = $emission->documents()->findOrFail($amendmentDocument->id);
    $legacyAmendmentInstrument = LegalInstrument::factory()
        ->ofType(LegalInstrumentType::SecuritizationTerm, '1º')
        ->create(['emission_id' => $emission->id]);
    LegalInstrumentDocument::factory()
        ->amendment(1, '2026-05-13')
        ->processed()
        ->create([
            'legal_instrument_id' => $legacyAmendmentInstrument->id,
            'document_id' => $amendmentDocument->id,
        ]);

    LegalInstrumentField::factory()->create([
        'legal_instrument_id' => $term->id,
        'field_key' => LegalInstrumentFieldKey::Indexer,
        'value_type' => LegalInstrumentFieldKey::Indexer->valueType(),
        'value' => 'Taxa DI',
        'value_numeric' => null,
        'effective_date' => '2026-05-08',
        'status' => LegalInstrumentFieldStatus::Confirmed,
        'legal_instrument_document_id' => $termInstrumentDocument->id,
        'document_id' => $termDocument->id,
        'clause' => '4.1.8',
        'page' => 24,
        'excerpt' => 'Variação acumulada de 100% da Taxa DI.',
    ]);
    LegalInstrumentField::factory()->create([
        'legal_instrument_id' => $term->id,
        'field_key' => LegalInstrumentFieldKey::MaturityDate,
        'value_type' => LegalInstrumentFieldKey::MaturityDate->valueType(),
        'value' => '2031-05-08',
        'value_numeric' => null,
        'value_date' => '2031-05-08',
        'effective_date' => '2026-05-08',
        'status' => LegalInstrumentFieldStatus::Confirmed,
        'legal_instrument_document_id' => $termInstrumentDocument->id,
        'document_id' => $termDocument->id,
        'clause' => '4.1.6',
        'page' => 23,
    ]);

    $ccb = LegalInstrument::factory()
        ->ofType(LegalInstrumentType::Ccb, 'Alto Bellevue')
        ->create(['emission_id' => $emission->id]);
    $ccbDocument = LegalInstrumentDocument::factory()
        ->original('2026-04-30')
        ->processed()
        ->create(['legal_instrument_id' => $ccb->id]);
    $ccbIndexer = legalInstrumentField($ccb, $ccbDocument, LegalInstrumentFieldKey::Indexer, 'Taxa DI');
    $ccbSpread = legalInstrumentField($ccb, $ccbDocument, LegalInstrumentFieldKey::Spread, '0.075', 0.075);
    $ccbAmortization = legalInstrumentField(
        $ccb,
        $ccbDocument,
        LegalInstrumentFieldKey::Amortization,
        'ccb_contractual_installments',
    );

    $propertyDocument = Document::factory()->create(['title' => 'Matrícula Mãe']);
    $propertyInstrument = LegalInstrument::factory()
        ->ofType(LegalInstrumentType::RealEstateFiduciaryAlienation, 'Mãe')
        ->create(['emission_id' => $emission->id]);
    $propertyInstrumentDocument = LegalInstrumentDocument::factory()
        ->withRole(LegalInstrumentDocumentRole::Registration, '2026-04-30')
        ->processed()
        ->create([
            'legal_instrument_id' => $propertyInstrument->id,
            'document_id' => $propertyDocument->id,
        ]);
    $misclassifiedIssueDate = LegalInstrumentField::factory()->create([
        'legal_instrument_id' => $propertyInstrument->id,
        'field_key' => LegalInstrumentFieldKey::IssueDate,
        'value_type' => LegalInstrumentFieldKey::IssueDate->valueType(),
        'value' => '2026-04-30',
        'value_numeric' => null,
        'value_date' => '2026-04-30',
        'effective_date' => '2026-04-30',
        'status' => LegalInstrumentFieldStatus::Confirmed,
        'legal_instrument_document_id' => $propertyInstrumentDocument->id,
        'document_id' => $propertyDocument->id,
    ]);

    return [
        'emission' => $emission,
        'term' => $term,
        'term_document' => $termDocument,
        'term_instrument_document' => $termInstrumentDocument,
        'amendment_document' => $amendmentDocument,
        'legacy_amendment_instrument' => $legacyAmendmentInstrument,
        'ccb' => $ccb,
        'ccb_spread' => $ccbSpread,
        'ccb_indexer' => $ccbIndexer,
        'ccb_amortization' => $ccbAmortization,
        'misclassified_issue_date' => $misclassifiedIssueDate,
    ];
}

function legalInstrumentField(
    LegalInstrument $instrument,
    LegalInstrumentDocument $document,
    LegalInstrumentFieldKey $key,
    string $value,
    ?float $numeric = null,
): LegalInstrumentField {
    return LegalInstrumentField::factory()->create([
        'legal_instrument_id' => $instrument->id,
        'field_key' => $key,
        'value_type' => $key->valueType(),
        'value' => $value,
        'value_numeric' => $numeric,
        'effective_date' => '2026-04-30',
        'status' => LegalInstrumentFieldStatus::Confirmed,
        'legal_instrument_document_id' => $document->id,
        'document_id' => $document->document_id,
    ]);
}
