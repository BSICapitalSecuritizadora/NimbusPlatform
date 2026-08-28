<?php

use App\Domain\PuCalculator\Enums\PuBaselineEvidenceStatus;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceType;
use App\Domain\PuCalculator\Enums\PuBaselineReadinessStatus;
use App\Domain\PuCalculator\Enums\PuBaselineRequirementStatus;
use App\Domain\PuCalculator\Exceptions\PuMakerCheckerException;
use App\Domain\PuCalculator\Services\AltoBellevuePrerequisiteCloser;
use App\Domain\PuCalculator\Services\PuBaselineReadinessService;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Enums\AccessPermission;
use App\Enums\LegalInstrumentFieldKey;
use App\Enums\LegalInstrumentFieldStatus;
use App\Enums\LegalInstrumentType;
use App\Models\BusinessCalendarYear;
use App\Models\BusinessHoliday;
use App\Models\Document;
use App\Models\EmissionPuBaselineEvidence;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuEvent;
use App\Models\EmissionPuParameter;
use App\Models\IndexRate;
use App\Models\IndexRateSourceGovernanceReview;
use App\Models\LegalInstrumentField;
use App\Models\Payment;
use App\Models\PuHistory;
use App\Models\User;
use App\Services\GeminiService;
use App\Services\LegalInstruments\AltoBellevueContractEvidenceBackfill;
use App\Services\LegalInstruments\AltoBellevueContractEvidenceReview;
use App\Services\LegalInstruments\InstrumentChangeReviewService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

/*
 * Phases 2B.5.10–2B.5.12 — Alto Bellevue prerequisite governance.
 * DO NOT RUN AUTOMATED TESTS in this phase — prepared for manual execution.
 * Covers Matrícula Mãe false negative, reviewer governance, Gates A/B/C, and readiness transitions.
 */

// =========================================================================
// Matrícula Mãe
// =========================================================================

it('diagnoses false negative in matricula_mae_preserved and confirms field 180 remains intact', function () {
    $fixture = altoBellevueEvidenceFixture();
    app(AltoBellevueContractEvidenceBackfill::class)->execute(write: true);
    $this->seed(RolesAndPermissionsSeeder::class);
    $reviewer = makeAdminUser();
    // Phase 2B.5.9 review (now with fix) — field 180 should be considered preserved
    $reviewService = app(AltoBellevueContractEvidenceReview::class);
    $result = $reviewService->execute($reviewer);

    expect($result['matricula_mae_preserved'])->toBeTrue();

    // Direct audit of field 180 (read-only)
    $audit = $reviewService->auditField180();

    // In this isolated test DB, ID 180 may not exist (auto-increment differs), so we check fallback logic
    // The key assertion is that the diagnostic no longer requires status=Confirmed for Matrícula Mãe
    // and that the review did not mutate the collateral field
    $matriculaField = $fixture['misclassified_issue_date']->refresh();
    expect($matriculaField->value_date?->toDateString())->toBe('2026-04-30')
        ->and($matriculaField->instrument->type)->not->toBe(LegalInstrumentType::SecuritizationTerm)
        ->and($matriculaField->has_conflict)->toBeFalse()
        ->and($matriculaField->supersedes_id)->toBeNull();

    // CRI field remains 2026-05-08 confirmed
    $criIssue = LegalInstrumentField::where('legal_instrument_id', $fixture['term']->id)
        ->where('field_key', LegalInstrumentFieldKey::IssueDate->value)
        ->where('status', LegalInstrumentFieldStatus::Confirmed->value)
        ->first();
    expect($criIssue)->not->toBeNull()
        ->and($criIssue->value_date?->toDateString())->toBe('2026-05-08');

    // No activity log for field 180 (collateral not touched by CRI review)
    $activities = Activity::where('log_name', InstrumentChangeReviewService::LOG_NAME)
        ->where('subject_type', LegalInstrumentField::class)
        ->where('subject_id', $matriculaField->id)
        ->count();
    expect($activities)->toBe(0);
});

it('keeps field 180 intact after prerequisites closer diagnostic', function () {
    $fixture = altoBellevueEvidenceFixture();
    $closer = app(AltoBellevuePrerequisiteCloser::class);
    $audit = $closer->auditField180();
    // In test DB, field 180 may not exist — we just verify the audit is read-only and does not mutate
    $countBefore = LegalInstrumentField::count();
    $diag = $closer->matriculaMaeDiagnostic();
    $countAfter = LegalInstrumentField::count();
    expect($countAfter)->toBe($countBefore)
        ->and($diag['field_180_audit'])->toBeArray();
});

// =========================================================================
// Reviewer governance
// =========================================================================

it('requires explicit reviewer for audited approvals (missing reviewer)', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    // Command-level governance is tested via Closer::resolveReviewer
    $closer = app(AltoBellevuePrerequisiteCloser::class);
    expect($closer->resolveReviewer('nonexistent@bsi.local'))->toBeNull()
        ->and($closer->resolveReviewer(''))->toBeNull();
});

it('rejects nonexistent reviewer', function () {
    $closer = app(AltoBellevuePrerequisiteCloser::class);
    expect($closer->resolveReviewer('ghost@bsi.local'))->toBeNull();
    expect($closer->resolveReviewer('999999'))->toBeNull();
});

it('rejects reviewer without permission', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $userWithoutPerm = User::factory()->create(['email' => 'noperm@bsi.local']);
    // Do not assign any role/permission
    $closer = app(AltoBellevuePrerequisiteCloser::class);
    expect($closer->resolveReviewer('noperm@bsi.local'))->toBeNull();
});

it('accepts explicit reviewer with required permission', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $reviewer = User::factory()->create(['email' => 'reviewer.alto@example.com']);
    $reviewer->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);
    $closer = app(AltoBellevuePrerequisiteCloser::class);
    expect($closer->resolveReviewer('reviewer.alto@example.com'))->not->toBeNull()
        ->and($closer->resolveReviewer((string) $reviewer->id))->not->toBeNull();
});

it('audits admin@bsi.local as generic seed account', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    // Ensure seed account exists (InitialDemoSeeder creates it, but we seed roles and create manually if needed)
    $admin = User::firstOrCreate(
        ['email' => 'admin@bsi.local'],
        ['name' => 'Admin Super', 'password' => bcrypt('Admin@123456'), 'approved_at' => now(), 'is_active' => true]
    );
    if (! $admin->hasRole('super-admin')) {
        $admin->assignRole('super-admin');
    }
    $closer = app(AltoBellevuePrerequisiteCloser::class);
    $audit = $closer->auditReviewer('admin@bsi.local');
    expect($audit['found'])->toBeTrue()
        ->and($audit['is_seed_account'])->toBeTrue()
        ->and($audit['how_created'])->toContain('InitialDemoSeeder')
        ->and($audit['is_generic'])->toBeTrue();
});

// =========================================================================
// Gate A — DI source B3 × BCB 4389
// =========================================================================

it('shows DI dossier summary and approves via explicit workflow (pending -> approved)', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);
    // Write a technically satisfied dossier (413/413 B)
    writeReadyDiDossier();

    $closer = app(AltoBellevuePrerequisiteCloser::class);
    $stateBefore = $closer->gateAState();
    expect($stateBefore['is_technical_satisfied'])->toBeTrue()
        ->and($stateBefore['is_approved'])->toBeFalse()
        ->and($stateBefore['summary_for_reviewer']['common_dates'])->toBe(413)
        ->and($stateBefore['summary_for_reviewer']['equal_values'])->toBe(413);

    $dry = $closer->gateADryRun();
    expect($dry['action'])->toBe('will_approve');

    $res = $closer->gateAExecute($reviewer, 'Phase 2B.5.10 Gate A: operational approval — 413/413 equal, B classification, representation transformation only, checksums valid.');
    expect($res['executed'])->toBeTrue()
        ->and($closer->gateAState()['is_approved'])->toBeTrue()
        ->and(IndexRateSourceGovernanceReview::where('source_code', 'bcb_sgs_4389')->where('status', 'approved')->exists())->toBeTrue();
});

it('is idempotent for DI source approval (already_approved)', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);
    writeReadyDiDossier();
    $closer = app(AltoBellevuePrerequisiteCloser::class);
    $closer->gateAExecute($reviewer, 'first approval');
    $second = $closer->gateAExecute($reviewer, 'second attempt');
    expect($second['executed'])->toBeFalse()
        ->and($second['idempotent'])->toBeTrue()
        ->and($closer->gateADryRun()['action'])->toBe('already_approved');
});

it('preserves checksum/revision and enforces maker-checker for DI source', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $executor = User::factory()->create();
    $executor->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);
    writeReadyDiDossier($executor->id);
    $closer = app(AltoBellevuePrerequisiteCloser::class);
    $state = $closer->gateAState();
    expect($state['dossier']['report_checksum'])->not->toBeEmpty()
        ->and($state['dossier']['artifact_checksum'])->not->toBeEmpty()
        ->and($state['dossier']['checksum_valid'])->toBeTrue();

    // Maker-checker: executor cannot approve own dossier
    expect(fn () => $closer->gateAExecute($executor, 'self-approval attempt'))->toThrow(PuMakerCheckerException::class);
});

// =========================================================================
// Gate B — Calendar BR_NATIONAL_HOLIDAYS 2026-2031
// =========================================================================

it('shows provisional -> confirmed for 2026-2031 with 9 holidays, zero conflicts, reproducible checksum', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    prepareNationalCalendar(confirmed: false);
    $closer = app(AltoBellevuePrerequisiteCloser::class);
    $state = $closer->gateBState();
    expect($state['calendar_code'])->toBe(BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS)
        ->and($state['technical_coverage_satisfied'])->toBeTrue()
        ->and($state['administratively_confirmed'])->toBeFalse()
        ->and(count($state['years']))->toBe(6);

    foreach ($state['years'] as $year) {
        expect($year['holiday_count'])->toBe(9)
            ->and($year['conflicts'])->toBe(0)
            ->and($year['overrides'])->toBe(0)
            ->and($year['checksum_reproducible'])->toBeTrue();
    }
});

it('confirms calendar years 2026-2031 via explicit reviewer (provisional -> confirmed)', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);
    prepareNationalCalendar(confirmed: false);
    $closer = app(AltoBellevuePrerequisiteCloser::class);
    $dry = $closer->gateBDryRun();
    expect($dry['action'])->toBe('will_confirm')
        ->and($dry['provisional_years'])->toHaveCount(6);

    $res = $closer->gateBExecute($reviewer);
    expect($res['executed'])->toBeTrue()
        ->and(count($res['confirmed_years']))->toBe(6)
        ->and(BusinessCalendarYear::where('calendar_code', BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS)->where('status', BusinessCalendarYear::STATUS_CONFIRMED)->count())->toBe(6);

    // Exclusions explicitly verified
    $stateAfter = $closer->gateBState();
    foreach ($stateAfter['years'] as $year) {
        expect($year['excluded_observances']['carnival'])->toBeTrue()
            ->and($year['excluded_observances']['good_friday'])->toBeTrue()
            ->and($year['excluded_observances']['corpus_christi'])->toBeTrue();
    }
});

it('cannot confirm incomplete year and remains provisional', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    prepareNationalCalendar(confirmed: false);
    // Simulate incomplete year by removing one holiday
    BusinessHoliday::where('calendar_code', BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS)->whereYear('holiday_date', 2027)->limit(1)->delete();
    $closer = app(AltoBellevuePrerequisiteCloser::class);
    $state = $closer->gateBState();
    $blocked = collect($state['years'])->firstWhere('year', 2027);
    expect($blocked['review_state'])->toBe('technical_review_blocked');

    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);
    $dry = $closer->gateBDryRun();
    expect($dry['action'])->toBe('blocked');
});

it('is idempotent for calendar confirmation (already_confirmed)', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);
    prepareNationalCalendar(confirmed: false);
    $closer = app(AltoBellevuePrerequisiteCloser::class);
    $closer->gateBExecute($reviewer);
    $second = $closer->gateBExecute($reviewer);
    expect($second['executed'])->toBeFalse()
        ->and($second['idempotent'])->toBeTrue()
        ->and($closer->gateBDryRun()['action'])->toBe('already_confirmed');
});

// =========================================================================
// Gate C — First integration
// =========================================================================

it('finds no strong evidence when only indicative documents are linked', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    // Link only an indicative announcement (weak)
    $doc = Document::factory()->create(['title' => 'Anúncio de Início — datas meramente indicativas 15/05/2026']);
    $emission->documents()->attach($doc);
    $closer = app(AltoBellevuePrerequisiteCloser::class);
    $search = $closer->gateCSearch();
    expect(collect($search['classification'])->where('strength', 'strong')->count())->toBe(0);

    $dry = $closer->gateCDryRun();
    expect($dry['action'])->toBe('no_strong_evidence')
        ->and($dry['state']['curve_start_date'])->toBe('PENDING');
});

it('classifies weak evidence correctly and does not promote it', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    $weakDoc = Document::factory()->create(['title' => 'Anúncio de Início — cronograma indicativo']);
    $emission->documents()->attach($weakDoc);
    $closer = app(AltoBellevuePrerequisiteCloser::class);
    $dry = $closer->gateCDryRun();
    expect($dry['action'])->toBe('no_strong_evidence');

    // Attempt to execute should not create approved evidence
    $this->seed(RolesAndPermissionsSeeder::class);
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $creator = User::factory()->create();
    $creator->givePermissionTo(AccessPermission::PuParametersConfigure->value);
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);
    $res = $closer->gateCExecute($creator, $reviewer);
    expect($res['executed'])->toBeFalse()
        ->and(EmissionPuBaselineEvidence::where('emission_id', $emission->id)->where('evidence_type', PuBaselineEvidenceType::FirstIntegralizationDate->value)->where('status', PuBaselineEvidenceStatus::Approved->value)->exists())->toBeFalse();
});

it('creates pending then approved evidence for strong B3 settlement document', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Permission::findOrCreate(AccessPermission::PuParametersConfigure->value);
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    $strongDoc = Document::factory()->create(['title' => 'Extrato de liquidação B3 — 15/05/2026 — 26E0017614']);
    $emission->documents()->attach($strongDoc);
    fakeAltoBellevueFirstIntegrationAnalysis();

    $creator = User::factory()->create();
    $creator->givePermissionTo(AccessPermission::PuParametersConfigure->value);
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);

    $closer = app(AltoBellevuePrerequisiteCloser::class);
    $search = $closer->gateCSearch($strongDoc->id);
    expect(collect($search['classification'])->where('strength', 'strong')->count())->toBeGreaterThan(0);

    $res = $closer->gateCExecute($creator, $reviewer, '2026-05-15', $strongDoc->id);
    expect($res['executed'])->toBeTrue()
        ->and($res['evidenced_value'])->toBe('2026-05-15')
        ->and(EmissionPuBaselineEvidence::where('emission_id', $emission->id)->where('evidence_type', PuBaselineEvidenceType::FirstIntegralizationDate->value)->where('status', PuBaselineEvidenceStatus::Approved->value)->exists())->toBeTrue();

    // Quantity remains independent
    expect(EmissionPuBaselineEvidence::where('emission_id', $emission->id)->where('evidence_type', PuBaselineEvidenceType::IntegralizedQuantity->value)->exists())->toBeFalse();
});

it('keeps quantity separate from unit PU after integration proven', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Permission::findOrCreate(AccessPermission::PuParametersConfigure->value);
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    prepareNationalCalendar(confirmed: true);
    approveReadyDiDossier();
    $doc = Document::factory()->create(['title' => 'Extrato de liquidação B3 — 15/05/2026']);
    $emission->documents()->attach($doc);
    fakeAltoBellevueFirstIntegrationAnalysis();
    $creator = User::factory()->create();
    $creator->givePermissionTo(AccessPermission::PuParametersConfigure->value);
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);
    $closer = app(AltoBellevuePrerequisiteCloser::class);
    $closer->gateCExecute($creator, $reviewer, '2026-05-15', $doc->id);

    $report = app(PuBaselineReadinessService::class)->evaluate($emission->fresh(), CarbonImmutable::parse('2026-08-27'));
    expect($report->candidateConfiguration['curve_start_date'])->toBe('2026-05-15')
        ->and($report->quantityDiagnostics['required_for_unit_pu'])->toBeFalse()
        ->and($report->requirement('integralized_quantity')->blocks)->toBe(['aggregate_outputs']);
});

// =========================================================================
// Readiness transitions
// =========================================================================

it('remains blocked after DI source approved only', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    $this->seed(RolesAndPermissionsSeeder::class);
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);
    writeReadyDiDossier();
    app(AltoBellevuePrerequisiteCloser::class)->gateAExecute($reviewer, 'approve DI');

    $report = app(PuBaselineReadinessService::class)->evaluate($emission->fresh(), CarbonImmutable::parse('2026-08-27'));
    expect($report->status)->toBe(PuBaselineReadinessStatus::Blocked)
        ->and($report->requirement('index_source_operational_approval')->isSatisfied())->toBeTrue()
        ->and($report->requirement('calendar_administrative_confirmation')->isSatisfied())->toBeFalse();
});

it('remains blocked after DI source + calendar confirmed (still missing first integration)', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    $this->seed(RolesAndPermissionsSeeder::class);
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);
    writeReadyDiDossier();
    app(AltoBellevuePrerequisiteCloser::class)->gateAExecute($reviewer, 'approve DI');
    prepareNationalCalendar(confirmed: true);

    $report = app(PuBaselineReadinessService::class)->evaluate($emission->fresh(), CarbonImmutable::parse('2026-08-27'));
    expect($report->status)->toBe(PuBaselineReadinessStatus::Blocked)
        ->and($report->requirement('first_integralization_date')->isSatisfied())->toBeFalse();
});

it('reaches ready_for_candidate_configuration after DI + calendar + start date proven', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    $this->seed(RolesAndPermissionsSeeder::class);
    Permission::findOrCreate(AccessPermission::PuParametersConfigure->value);
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $diReviewer = User::factory()->create();
    $diReviewer->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);
    writeReadyDiDossier();
    app(AltoBellevuePrerequisiteCloser::class)->gateAExecute($diReviewer, 'approve DI');
    prepareNationalCalendar(confirmed: true);
    $doc = Document::factory()->create(['title' => 'Extrato de liquidação B3 — 15/05/2026 — 26E0017614']);
    $emission->documents()->attach($doc);
    fakeAltoBellevueFirstIntegrationAnalysis();
    $creator = User::factory()->create();
    $creator->givePermissionTo(AccessPermission::PuParametersConfigure->value);
    $intReviewer = User::factory()->create();
    $intReviewer->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);
    app(AltoBellevuePrerequisiteCloser::class)->gateCExecute($creator, $intReviewer, '2026-05-15', $doc->id);

    $report = app(PuBaselineReadinessService::class)->evaluate($emission->fresh(), CarbonImmutable::parse('2026-08-27'));
    expect($report->status)->toBe(PuBaselineReadinessStatus::ReadyForCandidateConfiguration)
        ->and($report->candidateConfiguration['curve_start_date'])->toBe('2026-05-15')
        ->and($report->requirement('pu_events_loaded')->status)->toBe(PuBaselineRequirementStatus::Blocking)
        ->and($report->requirement('index_snapshots_loaded')->status)->toBe(PuBaselineRequirementStatus::Blocking);
});

it('determines future snapshot window without loading rates', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    $this->seed(RolesAndPermissionsSeeder::class);
    Permission::findOrCreate(AccessPermission::PuParametersConfigure->value);
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $diReviewer = User::factory()->create();
    $diReviewer->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);
    writeReadyDiDossier();
    app(AltoBellevuePrerequisiteCloser::class)->gateAExecute($diReviewer, 'approve DI');
    prepareNationalCalendar(confirmed: true);
    $doc = Document::factory()->create(['title' => 'Extrato de liquidação B3 — 15/05/2026']);
    $emission->documents()->attach($doc);
    fakeAltoBellevueFirstIntegrationAnalysis();
    $creator = User::factory()->create();
    $creator->givePermissionTo(AccessPermission::PuParametersConfigure->value);
    $intReviewer = User::factory()->create();
    $intReviewer->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);
    $closer = app(AltoBellevuePrerequisiteCloser::class);
    $closer->gateCExecute($creator, $intReviewer, '2026-05-15', $doc->id);

    $window = $closer->futureSnapshotWindow();
    expect($window['resolvable'])->toBeTrue()
        ->and($window['curve_start_date'])->toBe('2026-05-15')
        ->and($window['premium_enabled'])->toBeTrue()
        ->and($window['premium_business_days'])->toBe(2);
});

it('does not persist EmissionPuParameter and remains idempotent', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    $this->seed(RolesAndPermissionsSeeder::class);
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);
    writeReadyDiDossier();
    $closer = app(AltoBellevuePrerequisiteCloser::class);
    $first = $closer->gateAExecute($reviewer, 'approve');
    $second = $closer->gateAExecute($reviewer, 'approve again');
    expect($first['executed'])->toBeTrue()
        ->and($second['idempotent'])->toBeTrue()
        ->and(EmissionPuParameter::whereBelongsTo($emission)->exists())->toBeFalse()
        ->and(IndexRate::count())->toBe(0)
        ->and(EmissionPuEvent::whereBelongsTo($emission)->exists())->toBeFalse();
});

// Phase 2B.5.10.1 — dry-run reason contract must be consistent and never throw
it('Gate A pending dry-run returns reason and does not throw', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    writeReadyDiDossier();
    $closer = app(AltoBellevuePrerequisiteCloser::class);
    $dry = $closer->gateADryRun();
    expect($dry['action'])->toBe('will_approve')
        ->and($dry['reason'])->toBeString()->not->toBeEmpty()
        ->and($dry['what_will_change'])->toBeString();
});

it('Gate A approved dry-run returns reason and does not throw', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);
    writeReadyDiDossier();
    $closer = app(AltoBellevuePrerequisiteCloser::class);
    $closer->gateAExecute($reviewer, 'approve for approved-state test');
    $dry = $closer->gateADryRun();
    expect($dry['action'])->toBe('already_approved')
        ->and($dry['reason'])->toBeString()->not->toBeEmpty();
});

it('Gate A renderer handles every supported state without Undefined array key', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $closer = app(AltoBellevuePrerequisiteCloser::class);
    $blocked = $closer->gateADryRun();
    expect($blocked['reason'])->toBeString();
    writeReadyDiDossier();
    $pending = $closer->gateADryRun();
    expect($pending['reason'])->toBeString();
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);
    $closer->gateAExecute($reviewer, 'approve');
    $approved = $closer->gateADryRun();
    expect($approved['reason'])->toBeString();
});

it('full dry-run does not throw while Gate A is administrative_pending', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    prepareNationalCalendar(confirmed: false);
    writeReadyDiDossier();
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    $closer = app(AltoBellevuePrerequisiteCloser::class);
    expect(fn () => $closer->gateADryRun())->not->toThrow(Throwable::class)
        ->and(fn () => $closer->gateBDryRun())->not->toThrow(Throwable::class)
        ->and(fn () => $closer->gateCDryRun())->not->toThrow(Throwable::class)
        ->and(fn () => $closer->evaluateReadiness())->not->toThrow(Throwable::class);
    $dryA = $closer->gateADryRun();
    $line = 'Dry-run action: '.$dryA['action'].' — '.($dryA['reason'] ?? $dryA['what_will_change'] ?? 'no reason');
    expect($line)->toContain('will_approve');
});

// Phase 2B.5.12 — content-backed classification and date validation
it('Gate C strong registrar position is eligible (not only B3)', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Permission::findOrCreate(AccessPermission::PuParametersConfigure->value);
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    $doc = Document::factory()->create(['title' => 'Posição do escriturador — 15/05/2026 — liquidado', 'file_path' => 'docs/posicao-escriturador.pdf']);
    $emission->documents()->attach($doc);
    fakeAltoBellevueFirstIntegrationAnalysis([
        'document_type' => 'registrar_position',
        'issuer' => [
            'name' => 'Escriturador Oficial S.A.',
            'role' => 'bookkeeper',
            'excerpt' => 'Escriturador Oficial S.A. certifica a posição liquidada.',
        ],
    ]);
    $closer = app(AltoBellevuePrerequisiteCloser::class);
    $search = $closer->gateCSearch($doc->id);
    expect(collect($search['classification'])->where('strength', 'strong')->count())->toBeGreaterThan(0);
    $dry = $closer->gateCDryRun($doc->id, '2026-05-15');
    expect($dry['action'])->toBe('will_create_pending_review');
});

it('Gate C medium document requires manual documentary review', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    $doc = Document::factory()->create(['title' => 'Mapa de distribuição — 15/05/2026']);
    $emission->documents()->attach($doc);
    $closer = app(AltoBellevuePrerequisiteCloser::class);
    $search = $closer->gateCSearch();
    $medium = collect($search['classification'])->firstWhere('document_id', $doc->id);
    expect($medium['strength'])->toBe('medium');
    expect($closer->gateCDryRun($doc->id)['action'])->toBe('manual_documentary_review_required');
});

it('Gate C filename alone without title date is not sufficient proof', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Permission::findOrCreate(AccessPermission::PuParametersConfigure->value);
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    $doc = Document::factory()->create([
        'title' => '',
        'file_name' => 'Extrato Liquidação 15-05-2026.pdf',
        'file_path' => 'documents/opaque-document-key.pdf',
    ]);
    $emission->documents()->attach($doc);
    fakeAltoBellevueFirstIntegrationAnalysis([
        'document_type' => 'other',
        'issuer' => ['name' => null, 'role' => 'unknown', 'excerpt' => null],
        'emission_identifiers' => [],
        'dates' => [],
        'candidate_integration_date' => null,
        'candidate_event_semantic' => null,
        'date_source' => null,
        'excerpt' => null,
        'proves_effective_settlement' => false,
        'confidence' => 0.10,
    ]);
    $closer = app(AltoBellevuePrerequisiteCloser::class);
    $dry = $closer->gateCDryRun($doc->id, '2026-05-15');
    expect($dry['action'])->toBe('insufficient_documentary_evidence')
        ->and($dry['state']['curve_start_date'])->toBe('PENDING');
});

it('Gate C blocks when integration-date does not match document evidence', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Permission::findOrCreate(AccessPermission::PuParametersConfigure->value);
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    $doc = Document::factory()->create(['title' => 'Extrato de liquidação B3 — 15/05/2026 — 26E0017614']);
    $emission->documents()->attach($doc);
    fakeAltoBellevueFirstIntegrationAnalysis();
    $creator = User::factory()->create();
    $creator->givePermissionTo(AccessPermission::PuParametersConfigure->value);
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);
    $closer = app(AltoBellevuePrerequisiteCloser::class);
    $res = $closer->gateCExecute($creator, $reviewer, '2026-05-16', $doc->id);
    expect($res['executed'])->toBeFalse()
        ->and($res['action'])->toBe('integration_date_does_not_match_document_evidence');
});

it('Gate C blocks when no reliable date is found and no integration-date supplied', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Permission::findOrCreate(AccessPermission::PuParametersConfigure->value);
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    // Create a strong-looking doc but without any date in title
    $doc = Document::factory()->create(['title' => 'Posição do escriturador — liquidado']);
    $emission->documents()->attach($doc);
    fakeAltoBellevueFirstIntegrationAnalysis([
        'document_type' => 'registrar_position',
        'dates' => [],
        'candidate_integration_date' => null,
        'candidate_event_semantic' => null,
        'date_source' => null,
        'excerpt' => null,
        'proves_effective_settlement' => false,
        'confidence' => 0.95,
    ]);
    $creator = User::factory()->create();
    $creator->givePermissionTo(AccessPermission::PuParametersConfigure->value);
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);
    $closer = app(AltoBellevuePrerequisiteCloser::class);
    $res = $closer->gateCExecute($creator, $reviewer, null, $doc->id);
    expect($res['executed'])->toBeFalse()
        ->and($res['action'])->toBe('document_does_not_prove_integration_date');
});

it('Gate C does not assume 15/05/2026 without strong evidence', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    $doc = Document::factory()->create(['title' => 'Anúncio de Início — datas meramente indicativas']);
    $emission->documents()->attach($doc);
    $closer = app(AltoBellevuePrerequisiteCloser::class);
    $dry = $closer->gateCDryRun();
    expect($dry['action'])->toBe('no_strong_evidence')
        ->and($dry['state']['curve_start_date'])->toBe('PENDING');
    // Even if we call gateCExecute without date, it must not default to 2026-05-15
    $this->seed(RolesAndPermissionsSeeder::class);
    Permission::findOrCreate(AccessPermission::PuParametersConfigure->value);
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $creator = User::factory()->create();
    $creator->givePermissionTo(AccessPermission::PuParametersConfigure->value);
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);
    $res = $closer->gateCExecute($creator, $reviewer);
    expect($res['executed'])->toBeFalse();
});

it('blocks an official-looking document that is not linked to Alto Bellevue', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    $document = Document::factory()->create(['title' => 'Extrato de liquidação B3']);

    $dryRun = app(AltoBellevuePrerequisiteCloser::class)->gateCDryRun($document->id, '2026-05-15');

    expect($dryRun['action'])->toBe('integration_document_not_linked_to_emission')
        ->and($dryRun['state']['curve_start_date'])->toBe('PENDING');
});

it('rejects an explicitly selected weak start announcement', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    $document = Document::factory()->create(['title' => 'Anúncio de Início — cronograma indicativo']);
    $emission->documents()->attach($document);

    $dryRun = app(AltoBellevuePrerequisiteCloser::class)->gateCDryRun($document->id, '2026-05-15');

    expect($dryRun['action'])->toBe('insufficient_documentary_evidence')
        ->and(EmissionPuBaselineEvidence::query()->whereBelongsTo($emission)->exists())->toBeFalse();
});

it('does not let a CLI date replace missing documentary proof', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Permission::findOrCreate(AccessPermission::PuParametersConfigure->value);
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    $document = Document::factory()->create(['title' => 'Posição oficial do custodiante']);
    $emission->documents()->attach($document);
    fakeAltoBellevueFirstIntegrationAnalysis([
        'document_type' => 'custodian_position',
        'issuer' => [
            'name' => 'Custodiante S.A.',
            'role' => 'custodian',
            'excerpt' => 'Custodiante S.A. apresenta a posição da emissão.',
        ],
        'dates' => [],
        'candidate_integration_date' => null,
        'candidate_event_semantic' => null,
        'date_source' => null,
        'excerpt' => null,
        'proves_effective_settlement' => false,
    ]);
    $creator = User::factory()->create();
    $creator->givePermissionTo(AccessPermission::PuParametersConfigure->value);
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);

    $result = app(AltoBellevuePrerequisiteCloser::class)->gateCExecute(
        $creator,
        $reviewer,
        '2026-05-15',
        $document->id,
    );

    expect($result['action'])->toBe('document_does_not_prove_integration_date')
        ->and(EmissionPuBaselineEvidence::query()->whereBelongsTo($emission)->exists())->toBeFalse();
});

it('blocks multiple plausible integration dates as ambiguous', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    $document = Document::factory()->create(['title' => 'Extrato de liquidação B3']);
    $emission->documents()->attach($document);
    fakeAltoBellevueFirstIntegrationAnalysis([
        'dates' => [
            [
                'date' => '2026-05-15',
                'semantic' => 'settlement_date',
                'source' => 'Document body, settlement row',
                'excerpt' => 'Liquidação em 15/05/2026.',
            ],
            [
                'date' => '2026-05-18',
                'semantic' => 'integration_date',
                'source' => 'Document body, integration row',
                'excerpt' => 'Integralização em 18/05/2026.',
            ],
        ],
        'candidate_integration_date' => null,
        'candidate_event_semantic' => null,
        'date_source' => null,
        'excerpt' => null,
        'ambiguous' => true,
        'ambiguity_reason' => 'O documento não informa qual evento inicia a remuneração.',
    ]);

    $dryRun = app(AltoBellevuePrerequisiteCloser::class)->gateCDryRun($document->id);

    expect($dryRun['action'])->toBe('ambiguous_integration_date')
        ->and($dryRun['document_analysis']['dates'])->toHaveCount(2)
        ->and($dryRun['state']['curve_start_date'])->toBe('PENDING');
});

it('blocks a strong document whose identifiers belong to another emission', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    $document = Document::factory()->create(['title' => 'Extrato de liquidação B3']);
    $emission->documents()->attach($document);
    fakeAltoBellevueFirstIntegrationAnalysis([
        'emission_identifiers' => [[
            'type' => 'isin_code',
            'value' => 'BROTHERCRI999',
            'excerpt' => 'ISIN BROTHERCRI999.',
        ]],
    ]);

    $dryRun = app(AltoBellevuePrerequisiteCloser::class)->gateCDryRun($document->id);

    expect($dryRun['action'])->toBe('integration_document_does_not_match_emission')
        ->and($dryRun['document_analysis']['emission_match'])->toBeFalse();
});

it('accepts the actual documented date instead of forcing the historical candidate', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Permission::findOrCreate(AccessPermission::PuParametersConfigure->value);
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    $document = Document::factory()->create(['title' => 'Comprovante oficial de primeira liquidação']);
    $emission->documents()->attach($document);
    fakeAltoBellevueFirstIntegrationAnalysis([
        'document_type' => 'official_settlement_proof',
        'dates' => [[
            'date' => '2026-05-18',
            'semantic' => 'settlement_date',
            'source' => 'Document body, settled transaction row',
            'excerpt' => 'Liquidação financeira efetivada em 18/05/2026.',
        ]],
        'candidate_integration_date' => '2026-05-18',
        'candidate_event_semantic' => 'settlement_date',
        'date_source' => 'Document body, settled transaction row',
        'excerpt' => 'Liquidação financeira efetivada em 18/05/2026.',
    ]);
    $creator = User::factory()->create();
    $creator->givePermissionTo(AccessPermission::PuParametersConfigure->value);
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);

    $result = app(AltoBellevuePrerequisiteCloser::class)->gateCExecute(
        $creator,
        $reviewer,
        '2026-05-18',
        $document->id,
    );

    expect($result['evidenced_value'])->toBe('2026-05-18')
        ->and($result['state']['curve_start_date'])->toBe('2026-05-18');
});

it('records pending and approval audit events with distinct maker and checker', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Permission::findOrCreate(AccessPermission::PuParametersConfigure->value);
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    $document = Document::factory()->create(['title' => 'Extrato de liquidação B3']);
    $emission->documents()->attach($document);
    fakeAltoBellevueFirstIntegrationAnalysis();
    $creator = User::factory()->create();
    $creator->givePermissionTo(AccessPermission::PuParametersConfigure->value);
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);

    $result = app(AltoBellevuePrerequisiteCloser::class)->gateCExecute(
        $creator,
        $reviewer,
        '2026-05-15',
        $document->id,
    );
    $evidence = EmissionPuBaselineEvidence::query()->whereBelongsTo($emission)->sole();
    $auditEvents = Activity::query()
        ->where('log_name', 'pu-baseline-evidence')
        ->where('subject_type', EmissionPuBaselineEvidence::class)
        ->where('subject_id', $evidence->id)
        ->oldest('id')
        ->get();

    expect($result['initial_status'])->toBe(PuBaselineEvidenceStatus::PendingReview->value)
        ->and($result['final_status'])->toBe(PuBaselineEvidenceStatus::Approved->value)
        ->and($result['creator'])->toBe($creator->email)
        ->and($result['reviewer'])->toBe($reviewer->email)
        ->and($auditEvents->pluck('event')->all())->toBe(['created_pending_review', 'approved'])
        ->and($auditEvents->pluck('causer_id')->all())->toBe([$creator->id, $reviewer->id]);
});

it('enforces maker checker and rolls back an attempted self approval', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Permission::findOrCreate(AccessPermission::PuParametersConfigure->value);
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    $document = Document::factory()->create(['title' => 'Extrato de liquidação B3']);
    $emission->documents()->attach($document);
    fakeAltoBellevueFirstIntegrationAnalysis();
    $actor = User::factory()->create();
    $actor->givePermissionTo([
        AccessPermission::PuParametersConfigure->value,
        AccessPermission::PuCalendarHomologationReview->value,
    ]);

    expect(fn () => app(AltoBellevuePrerequisiteCloser::class)->gateCExecute(
        $actor,
        $actor,
        '2026-05-15',
        $document->id,
    ))->toThrow(PuMakerCheckerException::class)
        ->and(EmissionPuBaselineEvidence::query()->whereBelongsTo($emission)->exists())->toBeFalse();
});

it('rejects an inactive unapproved or unauthorized evidence reviewer', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Permission::findOrCreate(AccessPermission::PuParametersConfigure->value);
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    $document = Document::factory()->create(['title' => 'Extrato de liquidação B3']);
    $emission->documents()->attach($document);
    fakeAltoBellevueFirstIntegrationAnalysis();
    $creator = User::factory()->create();
    $creator->givePermissionTo(AccessPermission::PuParametersConfigure->value);
    $reviewer = User::factory()->unapproved()->create(['is_active' => false]);

    expect(fn () => app(AltoBellevuePrerequisiteCloser::class)->gateCExecute(
        $creator,
        $reviewer,
        '2026-05-15',
        $document->id,
    ))->toThrow(AuthorizationException::class);
});

it('is idempotent for the same approved document and date', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Permission::findOrCreate(AccessPermission::PuParametersConfigure->value);
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    $document = Document::factory()->create(['title' => 'Extrato de liquidação B3']);
    $emission->documents()->attach($document);
    fakeAltoBellevueFirstIntegrationAnalysis();
    $creator = User::factory()->create();
    $creator->givePermissionTo(AccessPermission::PuParametersConfigure->value);
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);
    $closer = app(AltoBellevuePrerequisiteCloser::class);

    $first = $closer->gateCExecute($creator, $reviewer, '2026-05-15', $document->id);
    $second = $closer->gateCExecute($creator, $reviewer, '2026-05-15', $document->id);

    expect($first['executed'])->toBeTrue()
        ->and($second['idempotent'])->toBeTrue()
        ->and($closer->gateCDryRun()['action'])->toBe('already_approved')
        ->and(EmissionPuBaselineEvidence::query()->whereBelongsTo($emission)->count())->toBe(1);
});

it('reports an existing pending proposal instead of claiming there is no evidence', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    $document = Document::factory()->create(['title' => 'Extrato de liquidação B3']);
    $emission->documents()->attach($document);
    $pending = EmissionPuBaselineEvidence::factory()->create([
        'emission_id' => $emission->id,
        'document_id' => $document->id,
        'evidence_type' => PuBaselineEvidenceType::FirstIntegralizationDate,
        'evidenced_value' => '2026-05-15',
        'status' => PuBaselineEvidenceStatus::PendingReview,
    ]);
    fakeAltoBellevueFirstIntegrationAnalysis();
    $closer = app(AltoBellevuePrerequisiteCloser::class);

    $withoutDocument = $closer->gateCDryRun();
    $withDocument = $closer->gateCDryRun($document->id, '2026-05-15');

    expect($withoutDocument['action'])->toBe('pending_review_awaiting_reviewer')
        ->and($withoutDocument['state']['curve_start_date'])->toBe('PENDING')
        ->and($withDocument['action'])->toBe('will_approve_pending_review')
        ->and($withDocument['existing_evidence'])->toBe($pending->id);
});

it('reports a conflict instead of replacing the approved evidence when another document proves a different date', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Permission::findOrCreate(AccessPermission::PuParametersConfigure->value);
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    $proving = Document::factory()->create(['title' => 'Extrato de liquidação B3']);
    $contradicting = Document::factory()->create(['title' => 'Comprovante oficial de primeira liquidação']);
    $emission->documents()->attach([$proving->id, $contradicting->id]);
    $creator = User::factory()->create();
    $creator->givePermissionTo(AccessPermission::PuParametersConfigure->value);
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);

    fakeAltoBellevueFirstIntegrationAnalysis();
    app(AltoBellevuePrerequisiteCloser::class)->gateCExecute($creator, $reviewer, '2026-05-15', $proving->id);

    fakeAltoBellevueFirstIntegrationAnalysis([
        'document_type' => 'official_settlement_proof',
        'dates' => [[
            'date' => '2026-05-18',
            'semantic' => 'settlement_date',
            'source' => 'Document body, settled transaction row',
            'excerpt' => 'Liquidação financeira efetivada em 18/05/2026.',
        ]],
        'candidate_integration_date' => '2026-05-18',
        'date_source' => 'Document body, settled transaction row',
        'excerpt' => 'Liquidação financeira efetivada em 18/05/2026.',
    ]);
    $closer = app(AltoBellevuePrerequisiteCloser::class);
    $dryRun = $closer->gateCDryRun($contradicting->id);
    $result = $closer->gateCExecute($creator, $reviewer, '2026-05-18', $contradicting->id);

    expect($dryRun['action'])->toBe('first_integralization_evidence_conflict')
        ->and($dryRun['approved_integration_date'])->toBe('2026-05-15')
        ->and($dryRun['documented_integration_date'])->toBe('2026-05-18')
        ->and($result['executed'])->toBeFalse()
        ->and($result['action'])->toBe('first_integralization_evidence_conflict')
        ->and(EmissionPuBaselineEvidence::query()
            ->whereBelongsTo($emission)
            ->where('status', PuBaselineEvidenceStatus::Approved->value)
            ->pluck('evidenced_value')
            ->all())->toBe(['2026-05-15'])
        ->and($closer->gateCState()['curve_start_date'])->toBe('2026-05-15');
});

it('keeps an approved evidence idempotent when a later document adds no probative value', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Permission::findOrCreate(AccessPermission::PuParametersConfigure->value);
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    $proving = Document::factory()->create(['title' => 'Extrato de liquidação B3']);
    $indicative = Document::factory()->create(['title' => 'Anúncio de Início — cronograma indicativo']);
    $emission->documents()->attach([$proving->id, $indicative->id]);
    $creator = User::factory()->create();
    $creator->givePermissionTo(AccessPermission::PuParametersConfigure->value);
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);
    fakeAltoBellevueFirstIntegrationAnalysis();
    $closer = app(AltoBellevuePrerequisiteCloser::class);
    $closer->gateCExecute($creator, $reviewer, '2026-05-15', $proving->id);

    $dryRun = $closer->gateCDryRun($indicative->id);

    expect($dryRun['action'])->toBe('already_approved')
        ->and($dryRun['documented_integration_date'])->toBe('2026-05-15')
        ->and($dryRun['document_analysis']['analysis_status'])->toBe('insufficient_documentary_evidence')
        ->and(EmissionPuBaselineEvidence::query()->whereBelongsTo($emission)->count())->toBe(1);
});

it('reports a safe analysis failure without leaking extractor internals', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    $document = Document::factory()->create(['title' => 'Extrato de liquidação B3']);
    $emission->documents()->attach($document);
    mock(GeminiService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('extractFromDocumentWithPrompt')
            ->andThrow(new RuntimeException(
                '401 Unauthorized key=AIzaSyLEAKEDKEY payload=/var/www/storage/app/private/emissions/extrato.pdf',
            ));
    });

    $dryRun = app(AltoBellevuePrerequisiteCloser::class)->gateCDryRun($document->id, '2026-05-15');

    expect($dryRun['action'])->toBe('document_analysis_failed')
        ->and($dryRun['reason'])->not->toContain('AIzaSyLEAKEDKEY')
        ->and($dryRun['reason'])->not->toContain('/var/www/storage')
        ->and($dryRun['reason'])->not->toContain('401 Unauthorized')
        ->and($dryRun['state']['curve_start_date'])->toBe('PENDING')
        ->and(EmissionPuBaselineEvidence::query()->whereBelongsTo($emission)->exists())->toBeFalse();
});

it('closes only candidate configuration while leaving every financial side effect empty', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Permission::findOrCreate(AccessPermission::PuParametersConfigure->value);
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    prepareNationalCalendar(confirmed: true);
    approveReadyDiDossier();
    $document = Document::factory()->create(['title' => 'Extrato de liquidação B3']);
    $emission->documents()->attach($document);
    fakeAltoBellevueFirstIntegrationAnalysis();
    $creator = User::factory()->create();
    $creator->givePermissionTo(AccessPermission::PuParametersConfigure->value);
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);
    $before = [
        'parameters' => EmissionPuParameter::query()->count(),
        'rates' => IndexRate::query()->count(),
        'events' => EmissionPuEvent::query()->count(),
        'curves' => EmissionPuDailyCurve::query()->count(),
        'histories' => PuHistory::query()->count(),
        'payments' => Payment::query()->count(),
    ];

    $result = app(AltoBellevuePrerequisiteCloser::class)->gateCExecute(
        $creator,
        $reviewer,
        '2026-05-15',
        $document->id,
    );
    $after = [
        'parameters' => EmissionPuParameter::query()->count(),
        'rates' => IndexRate::query()->count(),
        'events' => EmissionPuEvent::query()->count(),
        'curves' => EmissionPuDailyCurve::query()->count(),
        'histories' => PuHistory::query()->count(),
        'payments' => Payment::query()->count(),
    ];

    expect($result['readiness']['status'])->toBe(PuBaselineReadinessStatus::ReadyForCandidateConfiguration->value)
        ->and($result['readiness']['pendingFields'])->toBe([])
        ->and($result['readiness']['requirements']['index_snapshots_loaded']['status'])->toBe(PuBaselineRequirementStatus::Blocking->value)
        ->and($result['readiness']['requirements']['pu_events_loaded']['status'])->toBe(PuBaselineRequirementStatus::Blocking->value)
        ->and($result['future_window']['calendar_required_from'])->not->toBeNull()
        ->and($result['future_window']['rate_required_from'])->not->toBeNull()
        ->and($result['future_window']['first_rate_lookups'])->not->toBe([])
        ->and($after)->toBe($before)
        ->and(EmissionPuBaselineEvidence::query()
            ->whereBelongsTo($emission)
            ->where('evidence_type', PuBaselineEvidenceType::IntegralizedQuantity->value)
            ->exists())->toBeFalse();
});

it('initialAudit confirms Gate A satisfied, Gate B satisfied, Gate C blocking', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    $this->seed(RolesAndPermissionsSeeder::class);
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);
    writeReadyDiDossier();
    app(AltoBellevuePrerequisiteCloser::class)->gateAExecute($reviewer, 'approve DI');
    prepareNationalCalendar(confirmed: true);
    $closer = app(AltoBellevuePrerequisiteCloser::class);
    $audit = $closer->initialAudit();
    expect($audit['gate_confirmation']['Gate A (DI source)'])->toContain('satisfied')
        ->and($audit['gate_confirmation']['Gate B (calendar)'])->toContain('satisfied')
        ->and($audit['gate_confirmation']['Gate C (first integration)'])->toContain('blocking')
        ->and($audit['curve_start_date_derived'])->toBe('PENDING')
        ->and($audit['emission']['if_code'])->toBe('26E0017614');
});

/** @param array<string, mixed> $overrides */
function fakeAltoBellevueFirstIntegrationAnalysis(array $overrides = []): void
{
    $payload = array_replace([
        'document_type' => 'b3_settlement_statement',
        'document_date' => '2026-05-15',
        'issuer' => [
            'name' => 'B3 S.A. — Brasil, Bolsa, Balcão',
            'role' => 'b3',
            'excerpt' => 'B3 S.A. — Brasil, Bolsa, Balcão certifica a liquidação.',
        ],
        'emission_identifiers' => [[
            'type' => 'if_code',
            'value' => '26E0017614',
            'excerpt' => 'Identificador da emissão: 26E0017614.',
        ]],
        'dates' => [
            [
                'date' => '2026-05-14',
                'semantic' => 'subscription_date',
                'source' => 'Document body, subscription row',
                'excerpt' => 'Data da subscrição: 14/05/2026.',
            ],
            [
                'date' => '2026-05-15',
                'semantic' => 'settlement_date',
                'source' => 'Document body, settlement event row',
                'excerpt' => 'Primeira liquidação financeira efetivada em 15/05/2026.',
            ],
            [
                'date' => '2026-05-18',
                'semantic' => 'registration_date',
                'source' => 'Document body, registration row',
                'excerpt' => 'Registro concluído em 18/05/2026.',
            ],
        ],
        'candidate_integration_date' => '2026-05-15',
        'candidate_event_semantic' => 'settlement_date',
        'date_source' => 'Document body, settlement event row',
        'excerpt' => 'Primeira liquidação financeira efetivada em 15/05/2026.',
        'proves_effective_settlement' => true,
        'ambiguous' => false,
        'ambiguity_reason' => null,
        'integralized_quantity' => '4000',
        'quantity_excerpt' => 'Quantidade liquidada: 4.000 CRI.',
        'confidence' => 0.99,
    ], $overrides);

    mock(GeminiService::class, function (MockInterface $mock) use ($payload): void {
        $mock->shouldReceive('extractFromDocumentWithPrompt')
            ->andReturn($payload);
    });
}
