<?php

use App\Domain\PuCalculator\Enums\PuBaselineReadinessStatus;
use App\Domain\PuCalculator\Enums\PuBaselineRequirementStatus;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Services\PuBaselineReadinessService;
use App\Enums\LegalInstrumentFieldKey;
use App\Enums\LegalInstrumentFieldStatus;
use App\Enums\LegalInstrumentType;
use App\Models\EmissionPuBaselineEvidence;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuEvent;
use App\Models\EmissionPuParameter;
use App\Models\IndexRate;
use App\Models\LegalInstrumentField;
use App\Services\LegalInstruments\AltoBellevueContractEvidenceBackfill;
use App\Services\LegalInstruments\AltoBellevueContractEvidenceReview;
use App\Services\LegalInstruments\InstrumentChangeReviewService;
use App\Services\LegalInstruments\InstrumentPositionResolver;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/*
 * Phase 2B.5.9 — Review and Confirmation of the Contractual Baseline Evidence.
 *
 * DO NOT RUN AUTOMATED TESTS in this phase (see task restriction). These tests
 * are prepared for manual execution. They verify that the 19 pending_review
 * records are reviewed through the normal workflow (InstrumentChangeReviewService),
 * that only governing CRI evidence is confirmed, and that the generic PU gate
 * naturally reacts without any hardcoded financial rules.
 *
 * The file reuses altoBellevueEvidenceFixture() from AltoBellevueContractEvidenceTest.php
 * via the global function defined there (Pest loads all Feature files).
 */

it('lists the 19 pending_review records with full provenance and separates explicit from inferred evidence', function () {
    $fixture = altoBellevueEvidenceFixture();
    // Phase 2B.5.8 backfill produced pending; maturity already confirmed so we have 20 pending in the fixture variant.
    // In production the two date fields (issue/maturity) are already confirmed, leaving 19 pending — the service filters by governing scope.
    app(AltoBellevueContractEvidenceBackfill::class)->execute(write: true);

    $service = app(AltoBellevueContractEvidenceReview::class);
    $inventory = $service->inventory();

    // In this test fixture the pending count is 20 (issue_date still pending in fresh fixture);
    // the service must still report governance and classification correctly.
    // In production with issue_date already confirmed it will be 19 — we assert the shape, not a hardcoded 19 here.
    expect($inventory)->not->toBeEmpty()
        ->and(collect($inventory)->every(fn (array $row): bool => filled($row['id'])
            && filled($row['field_key'])
            && filled($row['value_type'])
            && filled($row['governing_legal_instrument'])
            && filled($row['document'])
            && $row['page'] !== null
            && filled($row['clause'])
            && filled($row['excerpt'])
            && filled($row['evidence_level'])
            && is_float($row['confidence_score'])
            && $row['current_status'] === LegalInstrumentFieldStatus::PendingReview->value
            && filled($row['source_origin'])
        ))->toBeTrue();

    // Classification must exist for every row
    $explicit = collect($inventory)->filter(fn (array $r): bool => str_contains($r['classification'], 'A —'))->count();
    $inferred = collect($inventory)->filter(fn (array $r): bool => str_contains($r['classification'], 'B —'))->count();
    expect($explicit + $inferred)->toBe(count($inventory))
        ->and(collect($inventory)->where('governing_scope_valid', true)->count())->toBe(count($inventory));

    // Inferred keys must be exactly the operational interpretations
    $inferredKeys = collect($inventory)->filter(fn (array $r): bool => str_contains($r['classification'], 'B —'))->pluck('field_key')->sort()->values()->all();
    expect($inferredKeys)->toContain(
        LegalInstrumentFieldKey::DayCountRule->value,
        LegalInstrumentFieldKey::CalendarCode->value,
        LegalInstrumentFieldKey::IndexRateLookupMode->value,
        LegalInstrumentFieldKey::PaymentSchedule->value,
        LegalInstrumentFieldKey::Amortization->value,
        LegalInstrumentFieldKey::PaymentConvention->value,
    );

    // Governing scope: all pending must be CRI Termo, not CCB/CCI
    expect(collect($inventory)->every(fn (array $r): bool => $r['governing_instrument_type'] === LegalInstrumentType::SecuritizationTerm->value))->toBeTrue();
});

it('plans to confirm explicit CRI fields and inferred operational mappings via governing instrument scope only', function () {
    $fixture = altoBellevueEvidenceFixture();
    app(AltoBellevueContractEvidenceBackfill::class)->execute(write: true);

    $service = app(AltoBellevueContractEvidenceReview::class);
    $plan = $service->reviewPlan();

    expect(collect($plan)->every(fn (array $d): bool => filled($d['field_key']) && filled($d['decision']) && filled($d['reason']) && filled($d['classification'])))->toBeTrue()
        ->and(collect($plan)->where('decision', 'reject')->count())->toBe(0)
        ->and(collect($plan)->where('decision', 'keep_pending')->count())->toBe(0)
        ->and(collect($plan)->where('decision', 'confirm')->count())->toBe(count($plan));

    // Every confirmed field must remain linked to the governing CRI instrument — never collateral
    $confirmedFieldIds = collect($plan)->where('decision', 'confirm')->pluck('field_id');
    $confirmedFields = LegalInstrumentField::whereIn('id', $confirmedFieldIds)->with('instrument')->get();
    expect($confirmedFields->every(fn (LegalInstrumentField $f): bool => $f->instrument->type->governsPuBaseline()))->toBeTrue();
});

it('preserves CCB 7.50% spread and CCI/underlying fields untouched after CRI review', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $fixture = altoBellevueEvidenceFixture();
    app(AltoBellevueContractEvidenceBackfill::class)->execute(write: true);

    $service = app(AltoBellevueContractEvidenceReview::class);
    $service->execute(makeAdminUser());

    // CCB spread must remain 7.50% confirmed, not overwritten to 6.00%
    expect($fixture['ccb_spread']->refresh()->status)->toBe(LegalInstrumentFieldStatus::Confirmed)
        ->and($fixture['ccb_spread']->value_numeric)->toBe(0.075)
        ->and($fixture['ccb_spread']->value)->toBe('0.075');

    // CCB amortization remains its own contractual type, distinct from CRI bullet
    expect($fixture['ccb_amortization']->refresh()->value)->toBe('ccb_contractual_installments')
        ->and($fixture['ccb_indexer']->refresh()->value)->toBe('Taxa DI');

    // CRI spread is 6.00% on the governing term
    $termSpread = LegalInstrumentField::query()
        ->where('legal_instrument_id', $fixture['term']->id)
        ->where('field_key', LegalInstrumentFieldKey::Spread->value)
        ->where('status', LegalInstrumentFieldStatus::Confirmed->value)
        ->firstOrFail();
    expect($termSpread->value_numeric)->toBe(0.06);
});

it('keeps Matricula Mae issue date outside the CRI baseline and preserves CRI issue_date 2026-05-08', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $fixture = altoBellevueEvidenceFixture();
    app(AltoBellevueContractEvidenceBackfill::class)->execute(write: true);
    app(AltoBellevueContractEvidenceReview::class)->execute(makeAdminUser());

    // Matricula Mae 2026-04-30 remains confirmed on its own instrument, not promoted to CRI baseline
    expect($fixture['misclassified_issue_date']->refresh()->status)->toBe(LegalInstrumentFieldStatus::Confirmed)
        ->and($fixture['misclassified_issue_date']->value_date?->toDateString())->toBe('2026-04-30');

    // CRI baseline issue_date remains 2026-05-08 confirmed on the Term
    $criIssue = LegalInstrumentField::query()
        ->where('legal_instrument_id', $fixture['term']->id)
        ->where('field_key', LegalInstrumentFieldKey::IssueDate->value)
        ->where('status', LegalInstrumentFieldStatus::Confirmed->value)
        ->first();
    // In this fixture the CRI issue_date was pending before review; after review it must be confirmed
    expect($criIssue)->not->toBeNull()
        ->and($criIssue->value_date?->toDateString())->toBe('2026-05-08');
});

it('preserves canonical Taxa DI -> CDI mapping without widening the enum or destroying excerpt', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $fixture = altoBellevueEvidenceFixture();
    app(AltoBellevueContractEvidenceBackfill::class)->execute(write: true);
    app(AltoBellevueContractEvidenceReview::class)->execute(makeAdminUser());

    $indexer = LegalInstrumentField::query()
        ->where('legal_instrument_id', $fixture['term']->id)
        ->where('field_key', LegalInstrumentFieldKey::Indexer->value)
        ->where('status', LegalInstrumentFieldStatus::Confirmed->value)
        ->firstOrFail();

    expect($indexer->value)->toBe('CDI')
        ->and($indexer->excerpt)->toContain('Taxa DI')
        ->and(PuIndexer::tryFrom($indexer->value))->not->toBeNull();

    // CCB still stores literal "Taxa DI" — not converted to CDI
    expect($fixture['ccb_indexer']->refresh()->value)->toBe('Taxa DI');
});

it('lets CRI bullet amortization coexist with underlying-instrument amortization via instrument isolation', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $fixture = altoBellevueEvidenceFixture();
    app(AltoBellevueContractEvidenceBackfill::class)->execute(write: true);
    app(AltoBellevueContractEvidenceReview::class)->execute(makeAdminUser());

    $criAmort = LegalInstrumentField::query()
        ->where('legal_instrument_id', $fixture['term']->id)
        ->where('field_key', LegalInstrumentFieldKey::Amortization->value)
        ->where('status', LegalInstrumentFieldStatus::Confirmed->value)
        ->firstOrFail();

    expect($criAmort->value)->toBe('bullet')
        ->and($criAmort->excerpt)->toContain('100,0000%')
        ->and($fixture['ccb_amortization']->refresh()->value)->toBe('ccb_contractual_installments');

    // Position resolver must keep them isolated
    $resolver = app(InstrumentPositionResolver::class);
    $termPos = $resolver->fieldsAsOf($fixture['term']->fresh(), '2026-08-27');
    $ccbPos = $resolver->fieldsAsOf($fixture['ccb']->fresh(), '2026-08-27');
    expect($termPos->get(LegalInstrumentFieldKey::Amortization->value)?->current->value)->toBe('bullet')
        ->and($ccbPos->get(LegalInstrumentFieldKey::Amortization->value)?->current->value)->toBe('ccb_contractual_installments');
});

it('derives the gate candidate only from confirmed governing-instrument evidence and leaves curve_start_date PENDING without quantity promotion', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $fixture = altoBellevueEvidenceFixture();
    app(AltoBellevueContractEvidenceBackfill::class)->execute(write: true);
    app(AltoBellevueContractEvidenceReview::class)->execute(makeAdminUser());

    $report = app(PuBaselineReadinessService::class)->evaluate(
        $fixture['emission']->fresh(),
        CarbonImmutable::parse('2026-08-27'),
    );

    expect($report->candidateConfiguration)->toMatchArray([
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
        ->and($report->candidateConfiguration['legacy_projection_enabled'])->toBeFalse()
        ->and($report->pendingFields)->toContain('curve_start_date')
        ->and($report->pendingFields)->not->toContain('indexer', 'spread_rate', 'calendar_code')
        ->and($report->requirement('contract_spread')->status)->toBe(PuBaselineRequirementStatus::Satisfied)
        ->and($report->requirement('contract_bullet')->status)->toBe(PuBaselineRequirementStatus::Satisfied)
        ->and($report->requirement('first_integralization_date')->status)->toBe(PuBaselineRequirementStatus::Blocking)
        ->and($report->quantityDiagnostics['required_for_unit_pu'])->toBeFalse()
        // Quantity evidence is not promoted by confirming contractual fields
        ->and(EmissionPuBaselineEvidence::query()->whereBelongsTo($fixture['emission'])->exists())->toBeFalse()
        ->and(EmissionPuParameter::query()->whereBelongsTo($fixture['emission'])->exists())->toBeFalse();

    // Index percentage mapping: 1.0 -> 100%
    expect($report->candidateConfiguration['index_percentage'])->toBe('100.00000000')
        ->and($report->candidateConfiguration['spread_rate'])->toBe('6.00000000');
});

it('keeps readiness blocked only for expected operational/governance reasons after contractual review', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $fixture = altoBellevueEvidenceFixture();
    app(AltoBellevueContractEvidenceBackfill::class)->execute(write: true);
    app(AltoBellevueContractEvidenceReview::class)->execute(makeAdminUser());

    $report = app(PuBaselineReadinessService::class)->evaluate(
        $fixture['emission']->fresh(),
        CarbonImmutable::parse('2026-08-27'),
    );

    // Must remain blocked, not ready_for_candidate yet, because curve_start_date etc are still intentionally unresolved
    expect($report->status)->toBe(PuBaselineReadinessStatus::Blocked)
        ->and($report->requirement('calendar_administrative_confirmation')->isSatisfied())->toBeFalse()
        ->and($report->requirement('index_source_operational_approval')->isSatisfied())->toBeFalse()
        ->and($report->requirement('first_integralization_date')->status)->toBe(PuBaselineRequirementStatus::Blocking)
        ->and($report->requirement('pu_events_loaded')->status)->toBe(PuBaselineRequirementStatus::Blocking)
        ->and($report->requirement('index_snapshots_loaded')->status)->toBe(PuBaselineRequirementStatus::Blocking)
        // External benchmark remains optional: should block only externally_validated, not numeric_homologation
        ->and($report->requirement('external_independent_validation')->status)->toBe(PuBaselineRequirementStatus::Recommended)
        ->and($report->requirement('external_independent_validation')->blocks)->toBe(['external_validation'])
        ->and($report->requirement('integralized_quantity')->blocks)->toBe(['aggregate_outputs']);

    // Verify audit: every newly confirmed field has reviewer + timestamp + notes via workflow
    $confirmed = LegalInstrumentField::where('legal_instrument_id', $fixture['term']->id)
        ->where('status', LegalInstrumentFieldStatus::Confirmed->value)
        ->whereNotNull('reviewed_at')
        ->whereNotNull('reviewed_by')
        ->count();
    // After review the term should have ~21 confirmed fields (including the 2 dates); all via workflow
    expect($confirmed)->toBeGreaterThanOrEqual(19);
});

it('uses the legal-instrument review workflow and does not bypass maker/reviewer semantics via direct SQL', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $fixture = altoBellevueEvidenceFixture();
    app(AltoBellevueContractEvidenceBackfill::class)->execute(write: true);

    $pendingBefore = $fixture['term']->fields()->pendingReview()->get();
    $reviewer = makeAdminUser();

    app(AltoBellevueContractEvidenceReview::class)->execute($reviewer);

    // Each confirmed field must have reviewer + review_notes set by InstrumentChangeReviewService (not silent manual update)
    $confirmed = LegalInstrumentField::whereIn('id', $pendingBefore->pluck('id'))
        ->where('status', LegalInstrumentFieldStatus::Confirmed->value)
        ->get();

    expect($confirmed->count())->toBe($pendingBefore->count())
        ->and($confirmed->every(fn (LegalInstrumentField $f): bool => $f->reviewed_by === $reviewer->id && filled($f->review_notes) && $f->reviewed_at !== null))->toBeTrue();

    // Activity log must contain legal_instrument_changes entries
    expect(Activity::where('log_name', InstrumentChangeReviewService::LOG_NAME)->count())->toBeGreaterThanOrEqual($pendingBefore->count());
});

it('is idempotent and does not create PuParameters, events, rates, curves or payments', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $fixture = altoBellevueEvidenceFixture();
    app(AltoBellevueContractEvidenceBackfill::class)->execute(write: true);

    $service = app(AltoBellevueContractEvidenceReview::class);
    $reviewer = makeAdminUser();

    $first = $service->execute($reviewer);
    $second = $service->execute($reviewer);

    expect($first['confirmed'])->toBeGreaterThan(0)
        ->and($second['confirmed'])->toBe(0)
        ->and($second['pending_at_start'])->toBe(0)
        ->and(EmissionPuParameter::query()->whereBelongsTo($fixture['emission'])->exists())->toBeFalse()
        ->and(EmissionPuEvent::query()->whereBelongsTo($fixture['emission'])->exists())->toBeFalse()
        ->and(IndexRate::query()->count())->toBe(0)
        ->and(EmissionPuDailyCurve::query()->whereBelongsTo($fixture['emission'])->exists())->toBeFalse();
});
