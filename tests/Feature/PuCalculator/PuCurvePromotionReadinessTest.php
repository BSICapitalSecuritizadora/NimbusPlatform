<?php

use App\Domain\PuCalculator\DTOs\PuCurvePromotionPlan;
use App\Domain\PuCalculator\Enums\PuCurveExternalValidationStatus;
use App\Domain\PuCalculator\Enums\PuCurveReviewStatus;
use App\Domain\PuCalculator\Enums\PuCurveRole;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Enums\PuExternalValidationDecision;
use App\Domain\PuCalculator\Services\AltoBellevuePrerequisiteCloser;
use App\Domain\PuCalculator\Services\PuCandidateExternalValidationService;
use App\Domain\PuCalculator\Services\PuCurvePromotionPlanService;
use App\Domain\PuCalculator\Services\PuCurvePromotionRequestService;
use App\Models\Emission;
use App\Models\EmissionPuCurvePromotion;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuExternalBenchmark;
use App\Models\EmissionPuExternalBenchmarkRow;
use App\Models\EmissionPuExternalValidation;
use App\Models\EmissionPuExternalValidationRow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Pu\PuCandidateGovernanceFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function promotionPlans(): PuCurvePromotionPlanService
{
    return app(PuCurvePromotionPlanService::class);
}

function promotionRequests(): PuCurvePromotionRequestService
{
    return app(PuCurvePromotionRequestService::class);
}

/**
 * Cenário mínimo da fase: uma curva operacional vigente e, criada DEPOIS dela,
 * uma candidate validada externamente por um revisor independente.
 *
 * A ordem importa: os consumidores operacionais elegem a curva vigente por
 * `MAX(id)`, então a candidate promovível é sempre a mais nova.
 *
 * @return array{
 *     emission:Emission,
 *     operational:EmissionPuCurveVersion,
 *     candidate:EmissionPuCurveVersion,
 *     validation:EmissionPuExternalValidation,
 *     benchmark:EmissionPuExternalBenchmark,
 *     maker:User,
 *     internalReviewer:User,
 *     externalReviewer:User,
 * }
 */
function promotionScenario(): array
{
    $emission = PuCandidateGovernanceFixture::emission();
    $operational = PuCandidateGovernanceFixture::operationalCurve($emission);
    $governed = PuCandidateGovernanceFixture::externallyValidatedCandidate($emission);

    return [
        'emission' => $emission->fresh(),
        'operational' => $operational->fresh(),
        ...$governed,
    ];
}

// ---------------------------------------------------------------------------
// Elegibilidade
// ---------------------------------------------------------------------------

it('reports promotion_not_ready when no candidate exists at all', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    PuCandidateGovernanceFixture::operationalCurve($emission);

    $plan = promotionPlans()->plan($emission->fresh());

    expect($plan->action)->toBe(PuCurvePromotionPlan::ACTION_NOT_READY)
        ->and($plan->candidateVersionId)->toBeNull()
        ->and($plan->externalValidationId)->toBeNull();
});

it('refuses to promote a version that is already operational', function () {
    $scenario = promotionScenario();

    $plan = promotionPlans()->plan($scenario['emission'], $scenario['operational']);

    expect($plan->action)->toBe(PuCurvePromotionPlan::ACTION_NOT_READY)
        ->and($plan->reason)->toContain('operational version never re-enters');
});

it('refuses a candidate whose internal review is still pending', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    PuCandidateGovernanceFixture::operationalCurve($emission);
    $candidate = EmissionPuCurveVersion::factory()->candidate()->create([
        'emission_id' => $emission->id,
        'calculation_version' => 'pending-review-candidate',
        'rows_count' => 1,
    ]);

    $plan = promotionPlans()->plan($emission->fresh(), $candidate);

    expect($plan->action)->toBe(PuCurvePromotionPlan::ACTION_NOT_READY)
        ->and($plan->promotionId)->toBeNull();
});

it('refuses a candidate whose internal review was rejected', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    PuCandidateGovernanceFixture::operationalCurve($emission);
    $candidate = EmissionPuCurveVersion::factory()->candidate()->create([
        'emission_id' => $emission->id,
        'calculation_version' => 'internally-rejected-candidate',
        'review_status' => PuCurveReviewStatus::Rejected,
        'reviewed_at' => now(),
        'rows_count' => 1,
    ]);

    expect(promotionPlans()->plan($emission->fresh(), $candidate)->action)
        ->toBe(PuCurvePromotionPlan::ACTION_NOT_READY);
});

it('refuses an internally approved candidate whose external validation is still pending', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    PuCandidateGovernanceFixture::operationalCurve($emission);
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);

    $plan = promotionPlans()->plan($emission->fresh(), $candidate);

    expect($plan->action)->toBe(PuCurvePromotionPlan::ACTION_NOT_READY)
        ->and($candidate->fresh()->external_validation_status)
        ->toBe(PuCurveExternalValidationStatus::Pending);
});

it('refuses a candidate that was externally rejected', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    PuCandidateGovernanceFixture::operationalCurve($emission);
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);
    $benchmark = PuCandidateGovernanceFixture::persistedExternalBenchmark($emission, [
        '2026-01-02' => '1000.0000000000000000',
        '2026-01-05' => '1010.0000000000000000',
        '2026-01-06' => '1020.0000000000000000',
    ]);
    $validation = PuCandidateGovernanceFixture::persistedExternalComparison($candidate, $benchmark);
    app(PuCandidateExternalValidationService::class)->write(
        $validation,
        PuExternalValidationDecision::Reject,
        (string) PuCandidateGovernanceFixture::externalReviewer()->id,
        'Divergência material na curva independente.',
    );

    $plan = promotionPlans()->plan($emission->fresh(), $candidate->fresh());

    expect($plan->action)->toBe(PuCurvePromotionPlan::ACTION_NOT_READY)
        ->and($candidate->fresh()->external_validation_status)
        ->toBe(PuCurveExternalValidationStatus::Rejected);
});

it('reports ready_to_request only for the externally validated candidate', function () {
    $scenario = promotionScenario();

    $plan = promotionPlans()->plan($scenario['emission']);

    expect($plan->action)->toBe(PuCurvePromotionPlan::ACTION_READY_TO_REQUEST)
        ->and($plan->candidateVersionId)->toBe($scenario['candidate']->id)
        ->and($plan->currentOperationalVersionId)->toBe($scenario['operational']->id)
        ->and($plan->externalValidationId)->toBe($scenario['validation']->id)
        ->and($plan->candidateChecksum)->toBe($scenario['candidate']->curve_checksum)
        ->and($plan->inputFingerprint)->toBe($scenario['candidate']->input_fingerprint)
        ->and($plan->rowsCount)->toBe($scenario['candidate']->rows_count)
        ->and($plan->benchmarkChecksum)->toBe($scenario['benchmark']->dataset_sha256)
        ->and($plan->comparisonChecksum)->toBe($scenario['validation']->comparison_sha256);
});

it('refuses to plan a candidate older than the operational version it would replace', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $governed = PuCandidateGovernanceFixture::externallyValidatedCandidate($emission);
    // Operacional criada DEPOIS da candidate: promover deixaria a seleção
    // operacional (MAX(id)) apontando para a versão substituída.
    $newerOperational = PuCandidateGovernanceFixture::operationalCurve($emission);

    $plan = promotionPlans()->plan($emission->fresh(), $governed['candidate']);

    expect($plan->action)->toBe(PuCurvePromotionPlan::ACTION_BASELINE_CONFLICT)
        ->and($plan->currentOperationalVersionId)->toBe($newerOperational->id)
        ->and($newerOperational->id)->toBeGreaterThan($governed['candidate']->id);
});

// ---------------------------------------------------------------------------
// Integridade da candidate
// ---------------------------------------------------------------------------

it('blocks promotion when the persisted row count no longer matches the dossier', function () {
    $scenario = promotionScenario();
    DB::table('emission_pu_curve_versions')
        ->where('id', $scenario['candidate']->id)
        ->update(['rows_count' => $scenario['candidate']->rows_count + 1]);

    $plan = promotionPlans()->plan($scenario['emission'], $scenario['candidate']->fresh());

    expect($plan->action)->toBe(PuCurvePromotionPlan::ACTION_INTEGRITY_FAILURE);
});

it('blocks promotion when the curve checksum no longer matches the persisted rows', function () {
    $scenario = promotionScenario();
    DB::table('emission_pu_curve_versions')
        ->where('id', $scenario['candidate']->id)
        ->update(['curve_checksum' => str_repeat('a', 64)]);

    expect(promotionPlans()->plan($scenario['emission'], $scenario['candidate']->fresh())->action)
        ->toBe(PuCurvePromotionPlan::ACTION_INTEGRITY_FAILURE);
});

it('blocks promotion when the input fingerprint is missing', function () {
    $scenario = promotionScenario();
    DB::table('emission_pu_curve_versions')
        ->where('id', $scenario['candidate']->id)
        ->update(['input_fingerprint' => null]);

    expect(promotionPlans()->plan($scenario['emission'], $scenario['candidate']->fresh())->action)
        ->toBe(PuCurvePromotionPlan::ACTION_NOT_READY);
});

it('blocks promotion when a persisted daily row value was tampered with', function () {
    $scenario = promotionScenario();
    $row = $scenario['candidate']->dailyCurves()->orderBy('curve_date')->firstOrFail();
    DB::table('emission_pu_daily_curves')
        ->where('id', $row->id)
        ->update(['updated_unit_value' => '9999.0000000000000000']);

    expect(promotionPlans()->plan($scenario['emission'], $scenario['candidate']->fresh())->action)
        ->toBe(PuCurvePromotionPlan::ACTION_INTEGRITY_FAILURE);
});

// ---------------------------------------------------------------------------
// Integridade do dossiê externo
// ---------------------------------------------------------------------------

it('blocks promotion when a benchmark row was tampered with after validation', function () {
    $scenario = promotionScenario();
    $row = EmissionPuExternalBenchmarkRow::query()
        ->where('benchmark_id', $scenario['benchmark']->id)
        ->orderBy('reference_date')
        ->firstOrFail();
    DB::table('emission_pu_external_benchmark_rows')
        ->where('id', $row->id)
        ->update(['unit_value' => '1357.0000000000000000']);

    expect(promotionPlans()->plan($scenario['emission'], $scenario['candidate']->fresh())->action)
        ->toBe(PuCurvePromotionPlan::ACTION_INTEGRITY_FAILURE);
});

it('blocks promotion when the benchmark dataset checksum diverges from the validated dossier', function () {
    $scenario = promotionScenario();
    DB::table('emission_pu_external_benchmarks')
        ->where('id', $scenario['benchmark']->id)
        ->update(['dataset_sha256' => str_repeat('b', 64)]);

    expect(promotionPlans()->plan($scenario['emission'], $scenario['candidate']->fresh())->action)
        ->toBe(PuCurvePromotionPlan::ACTION_INTEGRITY_FAILURE);
});

it('blocks promotion when a comparison row was tampered with after validation', function () {
    $scenario = promotionScenario();
    $row = EmissionPuExternalValidationRow::query()
        ->where('external_validation_id', $scenario['validation']->id)
        ->orderBy('reference_date')
        ->firstOrFail();
    DB::table('emission_pu_external_validation_rows')
        ->where('id', $row->id)
        ->update(['absolute_difference' => '77.0000000000000000']);

    expect(promotionPlans()->plan($scenario['emission'], $scenario['candidate']->fresh())->action)
        ->toBe(PuCurvePromotionPlan::ACTION_INTEGRITY_FAILURE);
});

it('blocks promotion when the comparison checksum diverges from the persisted comparison', function () {
    $scenario = promotionScenario();
    DB::table('emission_pu_external_validations')
        ->where('id', $scenario['validation']->id)
        ->update(['comparison_sha256' => str_repeat('c', 64)]);

    expect(promotionPlans()->plan($scenario['emission'], $scenario['candidate']->fresh())->action)
        ->toBe(PuCurvePromotionPlan::ACTION_INTEGRITY_FAILURE);
});

it('blocks promotion when the validated dossier has no independent reviewer', function () {
    $scenario = promotionScenario();
    DB::table('emission_pu_external_validations')
        ->where('id', $scenario['validation']->id)
        ->update(['reviewed_by' => null]);

    expect(promotionPlans()->plan($scenario['emission'], $scenario['candidate']->fresh())->action)
        ->toBe(PuCurvePromotionPlan::ACTION_INTEGRITY_FAILURE);
});

it('blocks promotion when the candidate checksum diverges from the one used at validation time', function () {
    $scenario = promotionScenario();
    DB::table('emission_pu_external_validations')
        ->where('id', $scenario['validation']->id)
        ->update(['candidate_checksum' => str_repeat('d', 64)]);

    expect(promotionPlans()->plan($scenario['emission'], $scenario['candidate']->fresh())->action)
        ->toBe(PuCurvePromotionPlan::ACTION_INTEGRITY_FAILURE);
});

// ---------------------------------------------------------------------------
// Pedido de promoção
// ---------------------------------------------------------------------------

it('records a promotion request that captures the operational baseline and every checksum', function () {
    $scenario = promotionScenario();
    $requester = PuCandidateGovernanceFixture::promotionRequester();

    $result = promotionRequests()->write($scenario['emission'], $scenario['candidate'], (string) $requester->id);

    $promotion = EmissionPuCurvePromotion::query()->sole();

    expect($result->action)->toBe(PuCurvePromotionRequestService::ACTION_REQUESTED)
        ->and($result->writes)->toBe(1)
        ->and($promotion->candidate_curve_version_id)->toBe($scenario['candidate']->id)
        ->and($promotion->previous_operational_curve_version_id)->toBe($scenario['operational']->id)
        ->and($promotion->external_validation_id)->toBe($scenario['validation']->id)
        ->and($promotion->candidate_checksum)->toBe($scenario['candidate']->curve_checksum)
        ->and($promotion->input_fingerprint)->toBe($scenario['candidate']->input_fingerprint)
        ->and($promotion->benchmark_dataset_sha256)->toBe($scenario['benchmark']->dataset_sha256)
        ->and($promotion->comparison_sha256)->toBe($scenario['validation']->comparison_sha256)
        ->and($promotion->rows_count)->toBe($scenario['candidate']->rows_count)
        ->and($promotion->requested_by)->toBe($requester->id)
        ->and($promotion->status->value)->toBe('pending_review');
});

it('captures a null operational baseline when the emission has no operational curve yet', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $governed = PuCandidateGovernanceFixture::externallyValidatedCandidate($emission);
    $requester = PuCandidateGovernanceFixture::promotionRequester();

    $result = promotionRequests()->write($emission->fresh(), $governed['candidate'], (string) $requester->id);

    expect($result->action)->toBe(PuCurvePromotionRequestService::ACTION_REQUESTED)
        ->and(EmissionPuCurvePromotion::query()->sole()->previous_operational_curve_version_id)->toBeNull();
});

it('leaves the candidate, the operational curve and every financial row untouched after a request', function () {
    $scenario = promotionScenario();
    $candidateSnapshot = PuCandidateGovernanceFixture::curveIdentitySnapshot($scenario['candidate']);
    $operationalRows = PuCandidateGovernanceFixture::dailyRowSnapshot($scenario['operational']);
    $requester = PuCandidateGovernanceFixture::promotionRequester();

    promotionRequests()->write($scenario['emission'], $scenario['candidate'], (string) $requester->id);

    $emission = $scenario['emission']->fresh();

    expect($scenario['candidate']->fresh()->curve_role)->toBe(PuCurveRole::Candidate)
        ->and($scenario['operational']->fresh()->curve_role)->toBe(PuCurveRole::Operational)
        ->and($scenario['operational']->fresh()->status)->toBe(PuCurveStatus::Generated)
        ->and($emission->latestPuCurveVersion()->first()?->id)->toBe($scenario['operational']->id)
        ->and($emission->currentPuCurveVersion()?->id)->toBe($scenario['operational']->id)
        ->and(EmissionPuDailyCurve::latestCalculationVersionForEmission($emission->id))
        ->toBe($scenario['operational']->calculation_version)
        ->and(PuCandidateGovernanceFixture::curveIdentitySnapshot($scenario['candidate']))->toBe($candidateSnapshot)
        ->and(PuCandidateGovernanceFixture::dailyRowSnapshot($scenario['operational']))->toBe($operationalRows);
});

it('is idempotent: a second request reports the existing one with zero writes', function () {
    $scenario = promotionScenario();
    $requester = PuCandidateGovernanceFixture::promotionRequester();
    promotionRequests()->write($scenario['emission'], $scenario['candidate'], (string) $requester->id);
    $before = PuCandidateGovernanceFixture::promotionCounts();

    $second = promotionRequests()->write(
        $scenario['emission']->fresh(),
        $scenario['candidate']->fresh(),
        (string) $requester->id,
    );

    expect($second->action)->toBe(PuCurvePromotionPlan::ACTION_ALREADY_REQUESTED)
        ->and($second->writes)->toBe(0)
        ->and(PuCandidateGovernanceFixture::promotionCounts())->toBe($before)
        ->and(EmissionPuCurvePromotion::query()->count())->toBe(1);
});

it('defaults to read-only: inspect never creates a promotion dossier', function () {
    $scenario = promotionScenario();
    $before = PuCandidateGovernanceFixture::promotionCounts();

    $result = promotionRequests()->inspect(
        $scenario['emission'],
        $scenario['candidate'],
        (string) PuCandidateGovernanceFixture::promotionRequester()->id,
    );

    expect($result->action)->toBe(PuCurvePromotionPlan::ACTION_READY_TO_REQUEST)
        ->and($result->writes)->toBe(0)
        ->and(PuCandidateGovernanceFixture::promotionCounts())->toBe($before);
});

// ---------------------------------------------------------------------------
// Autorização do solicitante
// ---------------------------------------------------------------------------

it('requires an explicit, existing, active, approved and authorized requester', function () {
    $scenario = promotionScenario();
    $unknown = PuCandidateGovernanceFixture::promotionRequester();
    $unknownId = (string) ($unknown->id + 9999);
    $inactive = PuCandidateGovernanceFixture::promotionRequester();
    $inactive->forceFill(['is_active' => false])->save();
    $unapproved = PuCandidateGovernanceFixture::promotionRequester();
    $unapproved->forceFill(['approved_at' => null])->save();
    $unauthorized = PuCandidateGovernanceFixture::actor([]);
    $before = PuCandidateGovernanceFixture::promotionCounts();

    $missing = promotionRequests()->write($scenario['emission'], $scenario['candidate'], null);
    $notFound = promotionRequests()->write($scenario['emission'], $scenario['candidate'], $unknownId);
    $inactiveResult = promotionRequests()->write($scenario['emission'], $scenario['candidate'], (string) $inactive->id);
    $unapprovedResult = promotionRequests()->write($scenario['emission'], $scenario['candidate'], (string) $unapproved->id);
    $unauthorizedResult = promotionRequests()->write($scenario['emission'], $scenario['candidate'], (string) $unauthorized->id);
    $authorized = promotionRequests()->write($scenario['emission'], $scenario['candidate'], (string) $unknown->id);

    expect($missing->action)->toBe(PuCurvePromotionRequestService::ACTION_ACTOR_REQUIRED)
        ->and($notFound->action)->toBe(PuCurvePromotionRequestService::ACTION_ACTOR_NOT_FOUND)
        ->and($inactiveResult->action)->toBe(PuCurvePromotionRequestService::ACTION_ACTOR_INACTIVE)
        ->and($unapprovedResult->action)->toBe(PuCurvePromotionRequestService::ACTION_ACTOR_UNAPPROVED)
        ->and($unauthorizedResult->action)->toBe(PuCurvePromotionRequestService::ACTION_ACTOR_UNAUTHORIZED)
        ->and($unauthorizedResult->reason)->toContain('pu.curve.promote')
        ->and($missing->writes + $notFound->writes + $inactiveResult->writes
            + $unapprovedResult->writes + $unauthorizedResult->writes)->toBe(0)
        ->and($authorized->action)->toBe(PuCurvePromotionRequestService::ACTION_REQUESTED)
        ->and(EmissionPuCurvePromotion::query()->count())->toBe(1)
        ->and($before['promotions'])->toBe(0);
});

it('does not accept pu.curve.homologate as a substitute for pu.curve.promote', function () {
    $scenario = promotionScenario();

    $result = promotionRequests()->write(
        $scenario['emission'],
        $scenario['candidate'],
        (string) PuCandidateGovernanceFixture::checker()->id,
    );

    expect($result->action)->toBe(PuCurvePromotionRequestService::ACTION_ACTOR_UNAUTHORIZED)
        ->and(EmissionPuCurvePromotion::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Auditoria
// ---------------------------------------------------------------------------

it('audits the promotion request without duplicating it on an idempotent retry', function () {
    $scenario = promotionScenario();
    $requester = PuCandidateGovernanceFixture::promotionRequester();

    promotionRequests()->write($scenario['emission'], $scenario['candidate'], (string) $requester->id);
    promotionRequests()->write($scenario['emission']->fresh(), $scenario['candidate']->fresh(), (string) $requester->id);

    $activities = Activity::query()
        ->where('description', 'pu_curve_promotion_requested')
        ->where('subject_id', $scenario['emission']->id)
        ->get();

    expect($activities)->toHaveCount(1)
        ->and($activities->first()->properties['candidate_version_id'])->toBe($scenario['candidate']->id)
        ->and($activities->first()->properties['previous_operational_version_id'])->toBe($scenario['operational']->id)
        ->and($activities->first()->properties['external_validation_id'])->toBe($scenario['validation']->id)
        ->and($activities->first()->properties['candidate_checksum'])->toBe($scenario['candidate']->curve_checksum)
        ->and($activities->first()->properties['comparison_sha256'])->toBe($scenario['validation']->comparison_sha256)
        ->and($activities->first()->properties)->not->toHaveKeys(['rows', 'storage_path', 'benchmark_rows']);
});

// ---------------------------------------------------------------------------
// Alto Bellevue real: Gate C bloqueado
// ---------------------------------------------------------------------------

it('reports promotion_not_ready for an Alto Bellevue emission with a blocked Gate C', function () {
    $emission = Emission::factory()->create([
        'name' => 'CRI Alto Bellevue',
        'type' => 'CRI',
        'if_code' => AltoBellevuePrerequisiteCloser::IF_CODE,
        'isin_code' => AltoBellevuePrerequisiteCloser::ISIN_CODE,
        'status' => 'active',
    ]);
    $before = PuCandidateGovernanceFixture::promotionCounts();

    $this->artisan('pu:alto-bellevue:promotion-status')
        ->expectsOutputToContain('Candidate: none')
        ->expectsOutputToContain('External validation: not available')
        ->expectsOutputToContain('Promotion: not ready')
        ->expectsOutputToContain('Action: promotion_not_ready')
        ->expectsOutputToContain('Writes: 0')
        ->expectsOutputToContain('No operational switch executed.')
        ->assertSuccessful();

    expect(PuCandidateGovernanceFixture::promotionCounts())->toBe($before)
        ->and($emission->fresh()->puCurvePromotions()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Comandos
// ---------------------------------------------------------------------------

it('keeps the request command read-only by default', function () {
    $scenario = promotionScenario();

    $this->artisan('pu:curve-candidate:request-promotion', ['version' => $scenario['candidate']->id])
        ->expectsOutputToContain('Action: ready_to_request')
        ->expectsOutputToContain('Writes: 0')
        ->assertSuccessful();

    expect(EmissionPuCurvePromotion::query()->count())->toBe(0);
});

it('requires an explicit actor when the request command is asked to write', function () {
    $scenario = promotionScenario();

    $this->artisan('pu:curve-candidate:request-promotion', [
        'version' => $scenario['candidate']->id,
        '--write' => true,
    ])
        ->expectsOutputToContain('Action: promotion_requester_required')
        ->assertFailed();

    expect(EmissionPuCurvePromotion::query()->count())->toBe(0);
});

it('lets --dry-run win over --write on the request command', function () {
    $scenario = promotionScenario();
    $requester = PuCandidateGovernanceFixture::promotionRequester();

    $this->artisan('pu:curve-candidate:request-promotion', [
        'version' => $scenario['candidate']->id,
        '--actor' => (string) $requester->id,
        '--dry-run' => true,
        '--write' => true,
    ])
        ->expectsOutputToContain('Action: ready_to_request')
        ->expectsOutputToContain('Writes: 0')
        ->assertSuccessful();

    expect(EmissionPuCurvePromotion::query()->count())->toBe(0);
});

it('records the request through the command when an authorized actor is given', function () {
    $scenario = promotionScenario();
    $requester = PuCandidateGovernanceFixture::promotionRequester();

    $this->artisan('pu:curve-candidate:request-promotion', [
        'version' => $scenario['candidate']->id,
        '--actor' => (string) $requester->id,
        '--write' => true,
    ])
        ->expectsOutputToContain('Action: promotion_requested')
        ->expectsOutputToContain('Writes: 1')
        ->assertSuccessful();

    expect(EmissionPuCurvePromotion::query()->sole()->requested_by)->toBe($requester->id)
        ->and($scenario['candidate']->fresh()->curve_role)->toBe(PuCurveRole::Candidate);
});
