<?php

use App\Domain\PuCalculator\Enums\PuCurvePromotionDecision;
use App\Domain\PuCalculator\Enums\PuCurvePromotionStatus;
use App\Domain\PuCalculator\Enums\PuCurveRole;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Services\PuCurvePromotionRequestService;
use App\Domain\PuCalculator\Services\PuCurvePromotionReviewService;
use App\Models\Emission;
use App\Models\EmissionPuCurvePromotion;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuExternalBenchmark;
use App\Models\EmissionPuExternalValidation;
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

function promotionReviews(): PuCurvePromotionReviewService
{
    return app(PuCurvePromotionReviewService::class);
}

/**
 * Pedido de promoção pendente sobre um cenário integralmente governado.
 *
 * @return array{
 *     emission:Emission,
 *     operational:EmissionPuCurveVersion,
 *     candidate:EmissionPuCurveVersion,
 *     validation:EmissionPuExternalValidation,
 *     benchmark:EmissionPuExternalBenchmark,
 *     promotion:EmissionPuCurvePromotion,
 *     maker:User,
 *     internalReviewer:User,
 *     externalReviewer:User,
 *     requester:User,
 *     reviewer:User,
 * }
 */
function promotionReviewScenario(): array
{
    $emission = PuCandidateGovernanceFixture::emission();
    $operational = PuCandidateGovernanceFixture::operationalCurve($emission);
    $governed = PuCandidateGovernanceFixture::externallyValidatedCandidate($emission);
    $requester = PuCandidateGovernanceFixture::promotionRequester();
    $result = app(PuCurvePromotionRequestService::class)->write(
        $emission->fresh(),
        $governed['candidate'],
        (string) $requester->id,
    );

    if ($result->action !== PuCurvePromotionRequestService::ACTION_REQUESTED) {
        throw new RuntimeException(sprintf(
            'Expected a recorded promotion request; got %s (%s).',
            $result->action,
            $result->reason,
        ));
    }

    return [
        'emission' => $emission->fresh(),
        'operational' => $operational->fresh(),
        ...$governed,
        'promotion' => EmissionPuCurvePromotion::query()->findOrFail($result->promotionId),
        'requester' => $requester,
        'reviewer' => PuCandidateGovernanceFixture::promotionReviewer(),
    ];
}

// ---------------------------------------------------------------------------
// Preflight
// ---------------------------------------------------------------------------

it('inspects a pending promotion without writing anything', function () {
    $scenario = promotionReviewScenario();
    $before = PuCandidateGovernanceFixture::promotionCounts();

    $result = promotionReviews()->inspect($scenario['promotion'], PuCurvePromotionDecision::Approve);

    expect($result->action)->toBe(PuCurvePromotionReviewService::ACTION_READY)
        ->and($result->writes)->toBe(0)
        ->and(PuCandidateGovernanceFixture::promotionCounts())->toBe($before)
        ->and($scenario['promotion']->fresh()->status)->toBe(PuCurvePromotionStatus::PendingReview);
});

it('reports promotion_not_found for a promotion that does not exist', function () {
    $result = promotionReviews()->inspect(null, PuCurvePromotionDecision::Approve);

    expect($result->action)->toBe(PuCurvePromotionReviewService::ACTION_NOT_FOUND)
        ->and($result->writes)->toBe(0);
});

// ---------------------------------------------------------------------------
// Aprovação e rejeição
// ---------------------------------------------------------------------------

it('approves a pending promotion without touching the operational curve', function () {
    $scenario = promotionReviewScenario();
    $candidateSnapshot = PuCandidateGovernanceFixture::curveIdentitySnapshot($scenario['candidate']);

    $result = promotionReviews()->write(
        $scenario['promotion'],
        PuCurvePromotionDecision::Approve,
        (string) $scenario['reviewer']->id,
    );

    $promotion = $scenario['promotion']->fresh();
    $emission = $scenario['emission']->fresh();

    expect($result->action)->toBe(PuCurvePromotionReviewService::ACTION_APPROVED)
        ->and($result->writes)->toBe(1)
        ->and($promotion->status)->toBe(PuCurvePromotionStatus::Approved)
        ->and($promotion->reviewed_by)->toBe($scenario['reviewer']->id)
        ->and($promotion->reviewed_at)->not->toBeNull()
        ->and($promotion->promoted_at)->toBeNull()
        ->and($promotion->executed_by)->toBeNull()
        ->and($scenario['candidate']->fresh()->curve_role)->toBe(PuCurveRole::Candidate)
        ->and($scenario['operational']->fresh()->status)->toBe(PuCurveStatus::Generated)
        ->and($emission->latestPuCurveVersion()->first()?->id)->toBe($scenario['operational']->id)
        ->and(PuCandidateGovernanceFixture::curveIdentitySnapshot($scenario['candidate']))->toBe($candidateSnapshot);
});

it('accepts an optional reason on approval', function () {
    $scenario = promotionReviewScenario();

    promotionReviews()->write(
        $scenario['promotion'],
        PuCurvePromotionDecision::Approve,
        (string) $scenario['reviewer']->id,
        '  Curva conferida contra o dossiê externo.  ',
    );

    expect($scenario['promotion']->fresh()->review_reason)
        ->toBe('Curva conferida contra o dossiê externo.');
});

it('requires a non empty reason to reject a promotion', function () {
    $scenario = promotionReviewScenario();
    $before = PuCandidateGovernanceFixture::promotionCounts();

    $blank = promotionReviews()->write(
        $scenario['promotion'],
        PuCurvePromotionDecision::Reject,
        (string) $scenario['reviewer']->id,
        '   ',
    );
    $missing = promotionReviews()->write(
        $scenario['promotion']->fresh(),
        PuCurvePromotionDecision::Reject,
        (string) $scenario['reviewer']->id,
    );

    expect($blank->action)->toBe(PuCurvePromotionReviewService::ACTION_REASON_REQUIRED)
        ->and($missing->action)->toBe(PuCurvePromotionReviewService::ACTION_REASON_REQUIRED)
        ->and($blank->writes + $missing->writes)->toBe(0)
        ->and(PuCandidateGovernanceFixture::promotionCounts())->toBe($before)
        ->and($scenario['promotion']->fresh()->status)->toBe(PuCurvePromotionStatus::PendingReview);
});

it('rejects a promotion and preserves it as a final historical artifact', function () {
    $scenario = promotionReviewScenario();

    $result = promotionReviews()->write(
        $scenario['promotion'],
        PuCurvePromotionDecision::Reject,
        (string) $scenario['reviewer']->id,
        'Troca operacional postergada para a próxima janela.',
    );

    $promotion = $scenario['promotion']->fresh();

    expect($result->action)->toBe(PuCurvePromotionReviewService::ACTION_REJECTED)
        ->and($promotion->status)->toBe(PuCurvePromotionStatus::Rejected)
        ->and($promotion->review_reason)->toBe('Troca operacional postergada para a próxima janela.')
        ->and($scenario['candidate']->fresh()->curve_role)->toBe(PuCurveRole::Candidate);
});

// ---------------------------------------------------------------------------
// Finalidade e idempotência
// ---------------------------------------------------------------------------

it('is idempotent when the same decision is repeated', function () {
    $scenario = promotionReviewScenario();
    promotionReviews()->write(
        $scenario['promotion'],
        PuCurvePromotionDecision::Approve,
        (string) $scenario['reviewer']->id,
    );
    $approvedAt = $scenario['promotion']->fresh()->reviewed_at;
    $before = PuCandidateGovernanceFixture::promotionCounts();

    $repeat = promotionReviews()->write(
        $scenario['promotion']->fresh(),
        PuCurvePromotionDecision::Approve,
        (string) $scenario['reviewer']->id,
    );

    expect($repeat->action)->toBe(PuCurvePromotionReviewService::ACTION_ALREADY_APPROVED)
        ->and($repeat->writes)->toBe(0)
        ->and(PuCandidateGovernanceFixture::promotionCounts())->toBe($before)
        ->and($scenario['promotion']->fresh()->reviewed_at?->toIso8601String())
        ->toBe($approvedAt?->toIso8601String());
});

it('is idempotent when the same rejection is repeated', function () {
    $scenario = promotionReviewScenario();
    promotionReviews()->write(
        $scenario['promotion'],
        PuCurvePromotionDecision::Reject,
        (string) $scenario['reviewer']->id,
        'Motivo original.',
    );

    $repeat = promotionReviews()->write(
        $scenario['promotion']->fresh(),
        PuCurvePromotionDecision::Reject,
        (string) $scenario['reviewer']->id,
        'Outro motivo qualquer.',
    );

    expect($repeat->action)->toBe(PuCurvePromotionReviewService::ACTION_ALREADY_REJECTED)
        ->and($repeat->writes)->toBe(0)
        ->and($scenario['promotion']->fresh()->review_reason)->toBe('Motivo original.');
});

it('treats an opposite decision on a final promotion as a conflict', function () {
    $scenario = promotionReviewScenario();
    promotionReviews()->write(
        $scenario['promotion'],
        PuCurvePromotionDecision::Approve,
        (string) $scenario['reviewer']->id,
    );

    $conflict = promotionReviews()->write(
        $scenario['promotion']->fresh(),
        PuCurvePromotionDecision::Reject,
        (string) $scenario['reviewer']->id,
        'Tentativa de reverter a aprovação.',
    );

    expect($conflict->action)->toBe(PuCurvePromotionReviewService::ACTION_REVIEW_CONFLICT)
        ->and($conflict->writes)->toBe(0)
        ->and($scenario['promotion']->fresh()->status)->toBe(PuCurvePromotionStatus::Approved);
});

it('treats an approval after a rejection as a conflict', function () {
    $scenario = promotionReviewScenario();
    promotionReviews()->write(
        $scenario['promotion'],
        PuCurvePromotionDecision::Reject,
        (string) $scenario['reviewer']->id,
        'Rejeitada por decisão de janela.',
    );

    $conflict = promotionReviews()->write(
        $scenario['promotion']->fresh(),
        PuCurvePromotionDecision::Approve,
        (string) $scenario['reviewer']->id,
    );

    expect($conflict->action)->toBe(PuCurvePromotionReviewService::ACTION_REVIEW_CONFLICT)
        ->and($scenario['promotion']->fresh()->status)->toBe(PuCurvePromotionStatus::Rejected);
});

it('refuses a new request for a candidate whose promotion was rejected', function () {
    $scenario = promotionReviewScenario();
    promotionReviews()->write(
        $scenario['promotion'],
        PuCurvePromotionDecision::Reject,
        (string) $scenario['reviewer']->id,
        'Rejeitada.',
    );

    $retry = app(PuCurvePromotionRequestService::class)->write(
        $scenario['emission']->fresh(),
        $scenario['candidate']->fresh(),
        (string) $scenario['requester']->id,
    );

    expect($retry->action)->toBe('promotion_already_rejected')
        ->and($retry->writes)->toBe(0)
        ->and(EmissionPuCurvePromotion::query()->count())->toBe(1);
});

it('blocks any direct mutation of a final promotion dossier at the model layer', function () {
    $scenario = promotionReviewScenario();
    promotionReviews()->write(
        $scenario['promotion'],
        PuCurvePromotionDecision::Reject,
        (string) $scenario['reviewer']->id,
        'Rejeitada.',
    );

    expect(fn () => $scenario['promotion']->fresh()->forceFill([
        'status' => PuCurvePromotionStatus::Approved,
    ])->save())->toThrow(LogicException::class)
        ->and(fn () => $scenario['promotion']->fresh()->delete())->toThrow(LogicException::class);
});

it('refuses to rewrite the captured identity of a pending promotion', function () {
    $scenario = promotionReviewScenario();

    expect(fn () => $scenario['promotion']->fresh()->forceFill([
        'candidate_checksum' => str_repeat('e', 64),
    ])->save())->toThrow(LogicException::class);
});

// ---------------------------------------------------------------------------
// Autorização e independência do revisor
// ---------------------------------------------------------------------------

it('requires an explicit, existing, active, approved and authorized reviewer', function () {
    $scenario = promotionReviewScenario();
    $unknownId = (string) (User::query()->max('id') + 9999);
    $inactive = PuCandidateGovernanceFixture::promotionReviewer();
    $inactive->forceFill(['is_active' => false])->save();
    $unapproved = PuCandidateGovernanceFixture::promotionReviewer();
    $unapproved->forceFill(['approved_at' => null])->save();
    $unauthorized = PuCandidateGovernanceFixture::actor([]);

    $missing = promotionReviews()->write($scenario['promotion'], PuCurvePromotionDecision::Approve, null);
    $notFound = promotionReviews()->write($scenario['promotion'], PuCurvePromotionDecision::Approve, $unknownId);
    $inactiveResult = promotionReviews()->write($scenario['promotion'], PuCurvePromotionDecision::Approve, (string) $inactive->id);
    $unapprovedResult = promotionReviews()->write($scenario['promotion'], PuCurvePromotionDecision::Approve, (string) $unapproved->id);
    $unauthorizedResult = promotionReviews()->write($scenario['promotion'], PuCurvePromotionDecision::Approve, (string) $unauthorized->id);

    expect($missing->action)->toBe(PuCurvePromotionReviewService::ACTION_REVIEWER_REQUIRED)
        ->and($notFound->action)->toBe(PuCurvePromotionReviewService::ACTION_REVIEWER_NOT_FOUND)
        ->and($inactiveResult->action)->toBe(PuCurvePromotionReviewService::ACTION_REVIEWER_INACTIVE)
        ->and($unapprovedResult->action)->toBe(PuCurvePromotionReviewService::ACTION_REVIEWER_UNAPPROVED)
        ->and($unauthorizedResult->action)->toBe(PuCurvePromotionReviewService::ACTION_REVIEWER_UNAUTHORIZED)
        ->and($unauthorizedResult->reason)->toContain('pu.curve.promote')
        ->and($scenario['promotion']->fresh()->status)->toBe(PuCurvePromotionStatus::PendingReview);
});

it('refuses the requester, the maker, the internal reviewer and the external reviewer as promotion reviewer', function () {
    $scenario = promotionReviewScenario();
    // Independência pressupõe autorização: cada ator precisa primeiro passar em
    // pu.curve.promote para então ser recusado por segregação de função.
    $requester = PuCandidateGovernanceFixture::authorizePromotionActor($scenario['requester']);
    $maker = PuCandidateGovernanceFixture::authorizePromotionActor($scenario['maker']);
    $internal = PuCandidateGovernanceFixture::authorizePromotionActor($scenario['internalReviewer']);
    $external = PuCandidateGovernanceFixture::authorizePromotionActor($scenario['externalReviewer']);

    $results = collect([$requester, $maker, $internal, $external])
        ->map(fn (User $actor) => promotionReviews()->write(
            $scenario['promotion']->fresh(),
            PuCurvePromotionDecision::Approve,
            (string) $actor->id,
        ));

    expect($results->pluck('action')->unique()->all())
        ->toBe([PuCurvePromotionReviewService::ACTION_INDEPENDENCE_VIOLATION])
        ->and($results->sum('writes'))->toBe(0)
        ->and($scenario['promotion']->fresh()->status)->toBe(PuCurvePromotionStatus::PendingReview);
});

it('surfaces the independence violation already in the preflight', function () {
    $scenario = promotionReviewScenario();
    $requester = PuCandidateGovernanceFixture::authorizePromotionActor($scenario['requester']);

    $result = promotionReviews()->inspect(
        $scenario['promotion'],
        PuCurvePromotionDecision::Approve,
        null,
        (string) $requester->id,
    );

    expect($result->action)->toBe(PuCurvePromotionReviewService::ACTION_INDEPENDENCE_VIOLATION)
        ->and($result->writes)->toBe(0);
});

it('accepts an independent fourth reviewer', function () {
    $scenario = promotionReviewScenario();

    $result = promotionReviews()->inspect(
        $scenario['promotion'],
        PuCurvePromotionDecision::Approve,
        null,
        (string) $scenario['reviewer']->id,
    );

    expect($result->action)->toBe(PuCurvePromotionReviewService::ACTION_READY)
        ->and($result->reviewerId)->toBe($scenario['reviewer']->id)
        ->and($scenario['reviewer']->id)->not->toBeIn([
            $scenario['requester']->id,
            $scenario['maker']->id,
            $scenario['internalReviewer']->id,
            $scenario['externalReviewer']->id,
        ]);
});

// ---------------------------------------------------------------------------
// Integridade revalidada no review
// ---------------------------------------------------------------------------

it('blocks the review when the captured candidate checksum no longer matches', function () {
    $scenario = promotionReviewScenario();
    DB::table('emission_pu_curve_versions')
        ->where('id', $scenario['candidate']->id)
        ->update(['curve_checksum' => str_repeat('f', 64)]);

    $result = promotionReviews()->write(
        $scenario['promotion']->fresh(),
        PuCurvePromotionDecision::Approve,
        (string) $scenario['reviewer']->id,
    );

    expect($result->action)->toBe(PuCurvePromotionReviewService::ACTION_INTEGRITY_FAILURE)
        ->and($result->writes)->toBe(0)
        ->and($scenario['promotion']->fresh()->status)->toBe(PuCurvePromotionStatus::PendingReview);
});

it('blocks the review when the benchmark dataset was tampered with', function () {
    $scenario = promotionReviewScenario();
    DB::table('emission_pu_external_benchmarks')
        ->where('id', $scenario['benchmark']->id)
        ->update(['dataset_sha256' => str_repeat('a', 64)]);

    expect(promotionReviews()->write(
        $scenario['promotion']->fresh(),
        PuCurvePromotionDecision::Approve,
        (string) $scenario['reviewer']->id,
    )->action)->toBe(PuCurvePromotionReviewService::ACTION_INTEGRITY_FAILURE);
});

it('blocks the review when the comparison checksum was tampered with', function () {
    $scenario = promotionReviewScenario();
    DB::table('emission_pu_external_validations')
        ->where('id', $scenario['validation']->id)
        ->update(['comparison_sha256' => str_repeat('b', 64)]);

    expect(promotionReviews()->write(
        $scenario['promotion']->fresh(),
        PuCurvePromotionDecision::Approve,
        (string) $scenario['reviewer']->id,
    )->action)->toBe(PuCurvePromotionReviewService::ACTION_INTEGRITY_FAILURE);
});

// ---------------------------------------------------------------------------
// Auditoria
// ---------------------------------------------------------------------------

it('audits approval and rejection without duplicating idempotent retries', function () {
    $scenario = promotionReviewScenario();
    promotionReviews()->write(
        $scenario['promotion'],
        PuCurvePromotionDecision::Approve,
        (string) $scenario['reviewer']->id,
    );
    promotionReviews()->write(
        $scenario['promotion']->fresh(),
        PuCurvePromotionDecision::Approve,
        (string) $scenario['reviewer']->id,
    );

    $approvals = Activity::query()
        ->where('description', 'pu_curve_promotion_approved')
        ->where('subject_id', $scenario['emission']->id)
        ->get();

    expect($approvals)->toHaveCount(1)
        ->and($approvals->first()->properties['promotion_id'])->toBe($scenario['promotion']->id)
        ->and($approvals->first()->properties['reviewer_id'])->toBe($scenario['reviewer']->id)
        ->and($approvals->first()->properties['decision'])->toBe('approve')
        ->and(Activity::query()->where('description', 'pu_curve_promoted_operational')->exists())->toBeFalse();
});

it('audits the rejection with its mandatory reason', function () {
    $scenario = promotionReviewScenario();

    promotionReviews()->write(
        $scenario['promotion'],
        PuCurvePromotionDecision::Reject,
        (string) $scenario['reviewer']->id,
        'Sem janela operacional nesta competência.',
    );

    $activity = Activity::query()
        ->where('description', 'pu_curve_promotion_rejected')
        ->where('subject_id', $scenario['emission']->id)
        ->sole();

    expect($activity->properties['decision'])->toBe('reject')
        ->and($activity->properties['reason'])->toBe('Sem janela operacional nesta competência.');
});

// ---------------------------------------------------------------------------
// Comando
// ---------------------------------------------------------------------------

it('keeps the review command read-only by default', function () {
    $scenario = promotionReviewScenario();

    $this->artisan('pu:curve-promotion:review', [
        'promotion' => $scenario['promotion']->id,
        '--approve' => true,
    ])
        ->expectsOutputToContain('Action: ready_for_promotion_review')
        ->expectsOutputToContain('Writes: 0')
        ->assertSuccessful();

    expect($scenario['promotion']->fresh()->status)->toBe(PuCurvePromotionStatus::PendingReview);
});

it('requires exactly one of --approve or --reject', function () {
    $scenario = promotionReviewScenario();

    $this->artisan('pu:curve-promotion:review', ['promotion' => $scenario['promotion']->id])
        ->assertFailed();

    $this->artisan('pu:curve-promotion:review', [
        'promotion' => $scenario['promotion']->id,
        '--approve' => true,
        '--reject' => true,
    ])->assertFailed();

    expect($scenario['promotion']->fresh()->status)->toBe(PuCurvePromotionStatus::PendingReview);
});

it('lets --dry-run win over --write on the review command', function () {
    $scenario = promotionReviewScenario();

    $this->artisan('pu:curve-promotion:review', [
        'promotion' => $scenario['promotion']->id,
        '--approve' => true,
        '--reviewer' => (string) $scenario['reviewer']->id,
        '--dry-run' => true,
        '--write' => true,
    ])
        ->expectsOutputToContain('Writes: 0')
        ->assertSuccessful();

    expect($scenario['promotion']->fresh()->status)->toBe(PuCurvePromotionStatus::PendingReview);
});

it('records the approval through the command and never switches the curve', function () {
    $scenario = promotionReviewScenario();

    $this->artisan('pu:curve-promotion:review', [
        'promotion' => $scenario['promotion']->id,
        '--approve' => true,
        '--reviewer' => (string) $scenario['reviewer']->id,
        '--write' => true,
    ])
        ->expectsOutputToContain('Action: promotion_approved')
        ->expectsOutputToContain('Writes: 1')
        ->expectsOutputToContain('Approval never switches the curve')
        ->assertSuccessful();

    expect($scenario['promotion']->fresh()->status)->toBe(PuCurvePromotionStatus::Approved)
        ->and($scenario['candidate']->fresh()->curve_role)->toBe(PuCurveRole::Candidate)
        ->and($scenario['emission']->fresh()->latestPuCurveVersion()->first()?->id)
        ->toBe($scenario['operational']->id);
});
