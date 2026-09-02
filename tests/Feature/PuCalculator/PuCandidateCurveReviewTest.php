<?php

use App\Domain\PuCalculator\Enums\PuCandidateReviewDecision;
use App\Domain\PuCalculator\Enums\PuCurveExternalValidationStatus;
use App\Domain\PuCalculator\Enums\PuCurveInternalValidationStatus;
use App\Domain\PuCalculator\Enums\PuCurveReviewStatus;
use App\Domain\PuCalculator\Enums\PuCurveRole;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Services\PuCandidateCurvePersistenceService;
use App\Domain\PuCalculator\Services\PuCandidateCurveReviewService;
use App\Domain\PuCalculator\Services\PuPersistedCurveChecksumService;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\Payment;
use App\Models\PuHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Pu\PuCandidateGovernanceFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Storage::fake('local');
    config([
        'pu_indexes.source_homologation.artifact_disk' => 'local',
        'pu_indexes.source_homologation.artifact_directory' => 'homologations/index-rate-sources',
        'pu_indexes.bcb.chunk_months' => 12,
        'pu_indexes.bcb.retries' => 1,
        'pu_indexes.bcb.retry_sleep_ms' => 0,
    ]);
});

function candidateReviewEmission(): Emission
{
    return Emission::factory()->create([
        'type' => 'CRI',
        'status' => 'active',
        'issued_quantity' => 1000,
    ]);
}

/**
 * Candidate persistida coerente: checksum e rows_count batem com as linhas
 * gravadas, que é o que o review reverifica antes de decidir.
 */
function candidateReviewVersion(
    Emission $emission,
    User $maker,
    array $overrides = [],
): EmissionPuCurveVersion {
    $version = EmissionPuCurveVersion::factory()->candidate()->create([
        'emission_id' => $emission->id,
        'calculation_version' => 'v2',
        'generated_by' => $maker->id,
        'rows_count' => 2,
        ...$overrides,
    ]);

    foreach (['2026-01-02', '2026-01-05'] as $curveDate) {
        EmissionPuDailyCurve::factory()->create([
            'emission_id' => $emission->id,
            'curve_version_id' => $version->id,
            'calculation_version' => $version->calculation_version,
            'curve_date' => $curveDate,
        ]);
    }

    $version->forceFill([
        'curve_checksum' => app(PuPersistedCurveChecksumService::class)->checksum($version->fresh()),
    ])->saveQuietly();

    return $version->fresh();
}

function candidateReviewOperationalVersion(Emission $emission): EmissionPuCurveVersion
{
    $version = EmissionPuCurveVersion::factory()->create([
        'emission_id' => $emission->id,
        'calculation_version' => 'v1',
        'rows_count' => 1,
    ]);

    EmissionPuDailyCurve::factory()->create([
        'emission_id' => $emission->id,
        'curve_version_id' => $version->id,
        'calculation_version' => 'v1',
        'curve_date' => '2026-01-02',
    ]);

    return $version->fresh();
}

it('reports a coherent persisted candidate as ready for the maker-checker decision', function () {
    $emission = candidateReviewEmission();
    $maker = PuCandidateGovernanceFixture::maker();
    $candidate = candidateReviewVersion($emission, $maker);

    $inspection = app(PuCandidateCurveReviewService::class)
        ->inspect($candidate, PuCandidateReviewDecision::Approve);

    expect($inspection->action)->toBe(PuCandidateCurveReviewService::ACTION_READY)
        ->and($inspection->writes)->toBe(0)
        ->and($inspection->curveRole)->toBe(PuCurveRole::Candidate->value)
        ->and($inspection->reviewStatus)->toBe(PuCurveReviewStatus::PendingReview->value)
        ->and($inspection->makerId)->toBe($maker->id);
});

it('refuses to review anything that is not a persisted candidate', function () {
    $emission = candidateReviewEmission();
    $operational = candidateReviewOperationalVersion($emission);
    $review = app(PuCandidateCurveReviewService::class);
    $checker = PuCandidateGovernanceFixture::checker();

    $missing = $review->write(null, PuCandidateReviewDecision::Approve, $checker->email);
    $result = $review->write($operational, PuCandidateReviewDecision::Approve, $checker->email);

    expect($missing->action)->toBe(PuCandidateCurveReviewService::ACTION_NOT_FOUND)
        ->and($result->action)->toBe(PuCandidateCurveReviewService::ACTION_NOT_REVIEWABLE)
        ->and($result->writes)->toBe(0)
        ->and($operational->fresh()->review_status)->toBe(PuCurveReviewStatus::NotApplicable)
        ->and($operational->fresh()->reviewed_by)->toBeNull();
});

it('refuses to approve a candidate whose internal validation did not pass', function () {
    $emission = candidateReviewEmission();
    $maker = PuCandidateGovernanceFixture::maker();
    $candidate = candidateReviewVersion($emission, $maker, [
        'internal_validation_status' => PuCurveInternalValidationStatus::Failed->value,
    ]);

    $result = app(PuCandidateCurveReviewService::class)->write(
        $candidate,
        PuCandidateReviewDecision::Approve,
        PuCandidateGovernanceFixture::checker()->email,
    );

    expect($result->action)->toBe(PuCandidateCurveReviewService::ACTION_NOT_REVIEWABLE)
        ->and($result->writes)->toBe(0)
        ->and($candidate->fresh()->review_status)->toBe(PuCurveReviewStatus::PendingReview);
});

it('refuses to review a candidate whose persisted rows no longer match the dossier', function () {
    $emission = candidateReviewEmission();
    $maker = PuCandidateGovernanceFixture::maker();
    $candidate = candidateReviewVersion($emission, $maker, ['rows_count' => 5]);

    $result = app(PuCandidateCurveReviewService::class)->write(
        $candidate,
        PuCandidateReviewDecision::Approve,
        PuCandidateGovernanceFixture::checker()->email,
    );

    expect($result->action)->toBe(PuCandidateCurveReviewService::ACTION_NOT_REVIEWABLE)
        ->and($result->writes)->toBe(0)
        ->and($candidate->fresh()->review_status)->toBe(PuCurveReviewStatus::PendingReview);
});

it('rejects every unusable reviewer before touching the candidate', function (
    string $case,
    string $expectedAction,
) {
    $emission = candidateReviewEmission();
    $maker = PuCandidateGovernanceFixture::maker();
    $candidate = candidateReviewVersion($emission, $maker);
    $identifier = match ($case) {
        'missing' => null,
        'unknown' => 'ghost@example.com',
        'inactive' => tap(PuCandidateGovernanceFixture::checker(), fn (User $user) => $user
            ->forceFill(['is_active' => false])->save())->email,
        'unapproved' => tap(PuCandidateGovernanceFixture::checker(), fn (User $user) => $user
            ->forceFill(['approved_at' => null])->save())->email,
        'unauthorized' => User::factory()->create()->email,
        'maker' => $maker->email,
    };

    $result = app(PuCandidateCurveReviewService::class)->write(
        $candidate,
        PuCandidateReviewDecision::Approve,
        $identifier,
    );

    expect($result->action)->toBe($expectedAction)
        ->and($result->writes)->toBe(0)
        ->and($candidate->fresh()->review_status)->toBe(PuCurveReviewStatus::PendingReview)
        ->and($candidate->fresh()->reviewed_by)->toBeNull();
})->with([
    'no reviewer' => ['missing', PuCandidateCurveReviewService::ACTION_REVIEWER_REQUIRED],
    'unknown reviewer' => ['unknown', PuCandidateCurveReviewService::ACTION_REVIEWER_NOT_FOUND],
    'inactive reviewer' => ['inactive', PuCandidateCurveReviewService::ACTION_REVIEWER_INACTIVE],
    'unapproved reviewer' => ['unapproved', PuCandidateCurveReviewService::ACTION_REVIEWER_UNAPPROVED],
    'unauthorized reviewer' => ['unauthorized', PuCandidateCurveReviewService::ACTION_REVIEWER_UNAUTHORIZED],
    'maker reviewing its own candidate' => ['maker', PuCandidateCurveReviewService::ACTION_MAKER_CHECKER_VIOLATION],
]);

it('records an internal approval without promoting anything', function () {
    $emission = candidateReviewEmission();
    $operational = candidateReviewOperationalVersion($emission);
    $maker = PuCandidateGovernanceFixture::maker();
    $checker = PuCandidateGovernanceFixture::checker();
    $candidate = candidateReviewVersion($emission, $maker);
    $rowsBefore = $candidate->dailyCurves()->pluck('updated_unit_value', 'curve_date')->all();

    $result = app(PuCandidateCurveReviewService::class)->write(
        $candidate,
        PuCandidateReviewDecision::Approve,
        $checker->email,
    );
    $reviewed = $candidate->fresh();

    expect($result->action)->toBe(PuCandidateCurveReviewService::ACTION_APPROVED)
        ->and($result->writes)->toBe(1)
        ->and($reviewed->review_status)->toBe(PuCurveReviewStatus::Approved)
        ->and($reviewed->reviewed_by)->toBe($checker->id)
        ->and($reviewed->reviewed_at)->not->toBeNull()
        ->and($reviewed->review_reason)->toBeNull()
        // Aprovada internamente NÃO é promovida.
        ->and($reviewed->curve_role)->toBe(PuCurveRole::Candidate)
        ->and($reviewed->external_validation_status)->toBe(PuCurveExternalValidationStatus::Pending)
        ->and($reviewed->status)->toBe(PuCurveStatus::Validated)
        ->and($reviewed->homologated_at)->toBeNull()
        ->and($reviewed->dailyCurves()->pluck('updated_unit_value', 'curve_date')->all())->toBe($rowsBefore)
        // O operacional continua exatamente onde estava.
        ->and($emission->fresh()->latestPuCurveVersion()->first()?->id)->toBe($operational->id)
        ->and($operational->fresh()->status)->toBe(PuCurveStatus::Generated)
        ->and(EmissionPuDailyCurve::latestCalculationVersionForEmission($emission->id))->toBe('v1')
        ->and(PuHistory::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and(Activity::query()->where('description', 'pu_candidate_curve_approved')->count())->toBe(1);
});

it('requires a non-empty reason to reject and preserves the artifact', function () {
    $emission = candidateReviewEmission();
    $maker = PuCandidateGovernanceFixture::maker();
    $checker = PuCandidateGovernanceFixture::checker();
    $candidate = candidateReviewVersion($emission, $maker);
    $review = app(PuCandidateCurveReviewService::class);

    $blank = $review->write($candidate, PuCandidateReviewDecision::Reject, $checker->email, '   ');

    expect($blank->action)->toBe(PuCandidateCurveReviewService::ACTION_REASON_REQUIRED)
        ->and($blank->writes)->toBe(0)
        ->and($candidate->fresh()->review_status)->toBe(PuCurveReviewStatus::PendingReview);

    $result = $review->write(
        $candidate->fresh(),
        PuCandidateReviewDecision::Reject,
        $checker->email,
        '  Divergência contra o benchmark do agente fiduciário.  ',
    );
    $reviewed = $candidate->fresh();

    expect($result->action)->toBe(PuCandidateCurveReviewService::ACTION_REJECTED)
        ->and($result->writes)->toBe(1)
        ->and($reviewed->review_status)->toBe(PuCurveReviewStatus::Rejected)
        ->and($reviewed->review_reason)->toBe('Divergência contra o benchmark do agente fiduciário.')
        ->and($reviewed->reviewed_by)->toBe($checker->id)
        ->and($reviewed->curve_role)->toBe(PuCurveRole::Candidate)
        ->and($reviewed->dailyCurves()->count())->toBe(2)
        ->and(Activity::query()->where('description', 'pu_candidate_curve_rejected')->count())->toBe(1);
});

it('treats a final review decision as immutable', function (
    string $firstDecision,
    string $secondDecision,
    string $expectedAction,
) {
    $emission = candidateReviewEmission();
    $maker = PuCandidateGovernanceFixture::maker();
    $checker = PuCandidateGovernanceFixture::checker();
    $candidate = candidateReviewVersion($emission, $maker);
    $review = app(PuCandidateCurveReviewService::class);
    $toDecision = fn (string $value): PuCandidateReviewDecision => PuCandidateReviewDecision::from($value);

    $review->write($candidate, $toDecision($firstDecision), $checker->email, 'Motivo original registrado.');
    $settled = $candidate->fresh();
    $second = $review->write($settled, $toDecision($secondDecision), $checker->email, 'Segunda tentativa.');
    $after = $candidate->fresh();

    expect($second->action)->toBe($expectedAction)
        ->and($second->writes)->toBe(0)
        ->and($after->review_status)->toBe($settled->review_status)
        ->and($after->reviewed_at?->toIso8601String())->toBe($settled->reviewed_at?->toIso8601String())
        ->and($after->review_reason)->toBe($settled->review_reason)
        ->and($after->curve_role)->toBe(PuCurveRole::Candidate)
        ->and(Activity::query()
            ->whereIn('description', ['pu_candidate_curve_approved', 'pu_candidate_curve_rejected'])
            ->count())->toBe(1);
})->with([
    'approve twice' => ['approve', 'approve', PuCandidateCurveReviewService::ACTION_ALREADY_APPROVED],
    'reject twice' => ['reject', 'reject', PuCandidateCurveReviewService::ACTION_ALREADY_REJECTED],
    'approved then rejected' => ['approve', 'reject', PuCandidateCurveReviewService::ACTION_REVIEW_CONFLICT],
    'rejected then approved' => ['reject', 'approve', PuCandidateCurveReviewService::ACTION_REVIEW_CONFLICT],
]);

it('freezes the whole artifact once the review decision is final', function () {
    $emission = candidateReviewEmission();
    $maker = PuCandidateGovernanceFixture::maker();
    $checker = PuCandidateGovernanceFixture::checker();
    $candidate = candidateReviewVersion($emission, $maker);

    app(PuCandidateCurveReviewService::class)->write(
        $candidate,
        PuCandidateReviewDecision::Approve,
        $checker->email,
    );
    $reviewed = $candidate->fresh();

    expect(fn () => $reviewed->forceFill(['review_reason' => 'reescrito'])->save())
        ->toThrow(LogicException::class)
        ->and(fn () => $candidate->fresh()->forceFill(['curve_role' => PuCurveRole::Operational])->save())
        ->toThrow(LogicException::class)
        ->and(fn () => $candidate->fresh()->delete())->toThrow(LogicException::class)
        ->and($candidate->fresh()->review_status)->toBe(PuCurveReviewStatus::Approved);
});

it('reviews the candidate produced by the real persistence pipeline without moving the operational curve', function () {
    $asOf = PuCandidateGovernanceFixture::asOf();
    $emission = PuCandidateGovernanceFixture::readyEmission($asOf);
    $operational = candidateReviewOperationalVersion($emission);
    $maker = PuCandidateGovernanceFixture::maker();
    $checker = PuCandidateGovernanceFixture::checker();

    $persisted = app(PuCandidateCurvePersistenceService::class)->write($emission->fresh(), $asOf, $maker->email);
    $candidate = EmissionPuCurveVersion::query()->whereKey($persisted->candidateVersionId)->firstOrFail();
    $rowsBefore = $candidate->dailyCurves()->count();
    $checksumBefore = $candidate->curve_checksum;

    $result = app(PuCandidateCurveReviewService::class)->write(
        $candidate,
        PuCandidateReviewDecision::Approve,
        $checker->email,
    );
    $reviewed = $candidate->fresh();

    expect($persisted->action)->toBe(PuCandidateCurvePersistenceService::ACTION_PERSISTED)
        ->and($result->action)->toBe(PuCandidateCurveReviewService::ACTION_APPROVED)
        ->and($reviewed->review_status)->toBe(PuCurveReviewStatus::Approved)
        ->and($reviewed->reviewed_by)->toBe($checker->id)
        ->and($reviewed->generated_by)->toBe($maker->id)
        ->and($reviewed->curve_role)->toBe(PuCurveRole::Candidate)
        ->and($reviewed->curve_checksum)->toBe($checksumBefore)
        ->and($reviewed->dailyCurves()->count())->toBe($rowsBefore)
        ->and($emission->fresh()->latestPuCurveVersion()->first()?->id)->toBe($operational->id)
        ->and(EmissionPuDailyCurve::latestCalculationVersionForEmission($emission->id))->toBe('v1')
        ->and($emission->fresh()->operationalPuDailyCurves()->count())->toBe(1)
        ->and(PuHistory::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0);
});
