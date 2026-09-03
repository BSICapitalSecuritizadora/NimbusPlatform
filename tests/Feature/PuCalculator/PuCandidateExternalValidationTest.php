<?php

use App\Domain\PuCalculator\Enums\PuCurveExternalValidationStatus;
use App\Domain\PuCalculator\Enums\PuCurveInternalValidationStatus;
use App\Domain\PuCalculator\Enums\PuCurveReviewStatus;
use App\Domain\PuCalculator\Enums\PuCurveRole;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Enums\PuExternalValidationDecision;
use App\Domain\PuCalculator\Services\PuCandidateExternalValidationService;
use App\Domain\PuCalculator\Services\PuHomologationReportService;
use App\Models\EmissionPuCurveVersion;
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

/** @return array<string, string> */
function externalDecisionValues(): array
{
    return [
        '2026-01-02' => '1000.0000000000000000',
        '2026-01-05' => '1010.0000000000000000',
        '2026-01-06' => '1020.0000000000000000',
    ];
}

function externalDecisions(): PuCandidateExternalValidationService
{
    return app(PuCandidateExternalValidationService::class);
}

/**
 * Cenário mínimo completo da fase: uma curva operacional intocável, uma
 * candidate aprovada internamente, um benchmark imutável e o dossiê de
 * comparação já persistido e pendente de decisão humana.
 *
 * @return array{
 *     operational:EmissionPuCurveVersion,
 *     candidate:EmissionPuCurveVersion,
 *     validation:EmissionPuExternalValidation,
 *     maker:User,
 *     checker:User,
 *     reviewer:User
 * }
 */
function externalDecisionScenario(array $benchmarkValues = []): array
{
    $emission = PuCandidateGovernanceFixture::emission();
    $operational = PuCandidateGovernanceFixture::operationalCurve($emission);
    $maker = PuCandidateGovernanceFixture::maker();
    $checker = PuCandidateGovernanceFixture::checker();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission, $maker, $checker);
    $benchmark = PuCandidateGovernanceFixture::persistedExternalBenchmark(
        $emission,
        $benchmarkValues === [] ? externalDecisionValues() : $benchmarkValues,
    );
    $validation = PuCandidateGovernanceFixture::persistedExternalComparison($candidate, $benchmark);

    return [
        'operational' => $operational->fresh(),
        'candidate' => $candidate->fresh(),
        'validation' => $validation->fresh(),
        'maker' => $maker,
        'checker' => $checker,
        'reviewer' => PuCandidateGovernanceFixture::externalReviewer(),
    ];
}

// ---------------------------------------------------------------------------
// Preflight sem escrita
// ---------------------------------------------------------------------------

it('inspects a pending dossier without writing anything', function () {
    $scenario = externalDecisionScenario();
    $before = PuCandidateGovernanceFixture::counts();

    $result = externalDecisions()->inspect(
        $scenario['validation'],
        PuExternalValidationDecision::Validate,
    );

    expect($result->action)->toBe(PuCandidateExternalValidationService::ACTION_READY)
        ->and($result->writes)->toBe(0)
        ->and(PuCandidateGovernanceFixture::counts())->toBe($before)
        ->and($scenario['validation']->fresh()->status)->toBe(PuCurveExternalValidationStatus::Pending);
});

it('surfaces an unauthorized or non independent reviewer already in the preflight', function () {
    $scenario = externalDecisionScenario();
    // Independência pressupõe autorização: o maker e o revisor interno
    // precisam de pu.curve.homologate antes de serem recusados por
    // segregação de função.
    $authorizedMaker = PuCandidateGovernanceFixture::authorizeExternalReviewer($scenario['maker']);
    $authorizedChecker = PuCandidateGovernanceFixture::authorizeExternalReviewer($scenario['checker']);

    $unauthorized = externalDecisions()->inspect(
        $scenario['validation'],
        PuExternalValidationDecision::Validate,
        null,
        (string) PuCandidateGovernanceFixture::actor([])->id,
    );
    $maker = externalDecisions()->inspect(
        $scenario['validation'],
        PuExternalValidationDecision::Validate,
        null,
        (string) $authorizedMaker->id,
    );
    $checker = externalDecisions()->inspect(
        $scenario['validation'],
        PuExternalValidationDecision::Validate,
        null,
        (string) $authorizedChecker->id,
    );
    $independent = externalDecisions()->inspect(
        $scenario['validation'],
        PuExternalValidationDecision::Validate,
        null,
        (string) $scenario['reviewer']->id,
    );

    expect($unauthorized->action)->toBe(PuCandidateExternalValidationService::ACTION_REVIEWER_UNAUTHORIZED)
        ->and($maker->action)->toBe(PuCandidateExternalValidationService::ACTION_INDEPENDENCE_VIOLATION)
        ->and($checker->action)->toBe(PuCandidateExternalValidationService::ACTION_INDEPENDENCE_VIOLATION)
        ->and($independent->action)->toBe(PuCandidateExternalValidationService::ACTION_READY)
        ->and($independent->reviewerId)->toBe($scenario['reviewer']->id)
        ->and($independent->writes)->toBe(0);
});

it('reports a missing dossier', function () {
    $result = externalDecisions()->inspect(null, PuExternalValidationDecision::Validate);

    expect($result->action)->toBe(PuCandidateExternalValidationService::ACTION_NOT_FOUND)
        ->and($result->writes)->toBe(0);
});

// ---------------------------------------------------------------------------
// Decisão de validação
// ---------------------------------------------------------------------------

it('externally validates the candidate and keeps it a non operational candidate', function () {
    $scenario = externalDecisionScenario();
    $operationalBefore = $scenario['operational']->only(['curve_role', 'status', 'rows_count', 'updated_at']);
    $latestOperationalBefore = EmissionPuCurveVersion::query()->operational()->latest('id')->value('id');

    $result = externalDecisions()->write(
        $scenario['validation'],
        PuExternalValidationDecision::Validate,
        (string) $scenario['reviewer']->id,
        'Curva independente do agente fiduciário confere linha a linha.',
    );
    $candidate = $scenario['candidate']->fresh();
    $validation = $scenario['validation']->fresh();

    expect($result->action)->toBe(PuCandidateExternalValidationService::ACTION_VALIDATED)
        ->and($result->writes)->toBe(1)
        ->and($validation->status)->toBe(PuCurveExternalValidationStatus::Validated)
        ->and($validation->reviewed_by)->toBe($scenario['reviewer']->id)
        ->and($validation->reviewed_at)->not->toBeNull()
        ->and($validation->review_reason)->toBe('Curva independente do agente fiduciário confere linha a linha.')
        // Externally validated candidate is still a candidate.
        ->and($candidate->external_validation_status)->toBe(PuCurveExternalValidationStatus::Validated)
        ->and($candidate->curve_role)->toBe(PuCurveRole::Candidate)
        ->and($candidate->status)->toBe(PuCurveStatus::Validated)
        ->and($candidate->review_status)->toBe(PuCurveReviewStatus::Approved)
        ->and($candidate->internal_validation_status)->toBe(PuCurveInternalValidationStatus::Passed)
        ->and($candidate->homologated_at)->toBeNull()
        // The operational curve is untouched and still the latest operational one.
        ->and($scenario['operational']->fresh()->only(['curve_role', 'status', 'rows_count', 'updated_at']))
        ->toEqual($operationalBefore)
        ->and(EmissionPuCurveVersion::query()->operational()->latest('id')->value('id'))
        ->toBe($latestOperationalBefore);
});

it('keeps the candidate identity and financial content untouched by the external decision', function () {
    $scenario = externalDecisionScenario();
    $before = $scenario['candidate']->only([
        'curve_role', 'status', 'review_status', 'internal_validation_status',
        'candidate_as_of', 'input_fingerprint', 'curve_checksum', 'rows_count',
        'generated_by', 'reviewed_by', 'reviewed_at',
    ]);
    $rowsBefore = $scenario['candidate']->dailyCurves()->orderBy('curve_date')->pluck('updated_unit_value')->all();

    externalDecisions()->write(
        $scenario['validation'],
        PuExternalValidationDecision::Validate,
        (string) $scenario['reviewer']->id,
    );
    $candidate = $scenario['candidate']->fresh();

    expect($candidate->only([
        'curve_role', 'status', 'review_status', 'internal_validation_status',
        'candidate_as_of', 'input_fingerprint', 'curve_checksum', 'rows_count',
        'generated_by', 'reviewed_by', 'reviewed_at',
    ]))->toEqual($before)
        ->and($candidate->dailyCurves()->orderBy('curve_date')->pluck('updated_unit_value')->all())
        ->toBe($rowsBefore);
});

it('validates without requiring a zero difference', function () {
    $scenario = externalDecisionScenario([
        '2026-01-02' => '900.0000000000000000',
        '2026-01-05' => '910.0000000000000000',
        '2026-01-06' => '920.0000000000000000',
    ]);

    $result = externalDecisions()->write(
        $scenario['validation'],
        PuExternalValidationDecision::Validate,
        (string) $scenario['reviewer']->id,
        'Divergência conhecida e aceita pelo revisor independente.',
    );

    expect($result->action)->toBe(PuCandidateExternalValidationService::ACTION_VALIDATED)
        ->and($scenario['validation']->fresh()->rows()->where('absolute_difference', '!=', '0.0000000000000000')->count())
        ->toBe(3);
});

it('validates a partial coverage dossier when the reviewer accepts it', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $maker = PuCandidateGovernanceFixture::maker();
    $checker = PuCandidateGovernanceFixture::checker();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission, $maker, $checker);
    $benchmark = PuCandidateGovernanceFixture::persistedExternalBenchmark($emission, [
        '2026-01-02' => '1000.0000000000000000',
    ]);
    $validation = PuCandidateGovernanceFixture::persistedExternalComparison($candidate, $benchmark);

    $result = externalDecisions()->write(
        $validation,
        PuExternalValidationDecision::Validate,
        (string) PuCandidateGovernanceFixture::externalReviewer()->id,
        'Cobertura parcial aceita: o gabarito só publica o primeiro dia útil.',
    );

    expect($result->action)->toBe(PuCandidateExternalValidationService::ACTION_VALIDATED)
        ->and($candidate->fresh()->external_validation_status)->toBe(PuCurveExternalValidationStatus::Validated)
        ->and($candidate->fresh()->curve_role)->toBe(PuCurveRole::Candidate);
});

// ---------------------------------------------------------------------------
// Decisão de rejeição
// ---------------------------------------------------------------------------

it('requires a non empty reason to reject', function (?string $reason) {
    $scenario = externalDecisionScenario();
    $before = PuCandidateGovernanceFixture::counts();

    $result = externalDecisions()->write(
        $scenario['validation'],
        PuExternalValidationDecision::Reject,
        (string) $scenario['reviewer']->id,
        $reason,
    );

    expect($result->action)->toBe(PuCandidateExternalValidationService::ACTION_REASON_REQUIRED)
        ->and($result->writes)->toBe(0)
        ->and($scenario['validation']->fresh()->status)->toBe(PuCurveExternalValidationStatus::Pending)
        ->and($scenario['candidate']->fresh()->external_validation_status)
        ->toBe(PuCurveExternalValidationStatus::Pending)
        ->and(PuCandidateGovernanceFixture::counts())->toBe($before);
})->with([
    'null reason' => null,
    'blank reason' => '   ',
]);

it('externally rejects the candidate without changing its role or the operational curve', function () {
    $scenario = externalDecisionScenario();
    $operationalBefore = $scenario['operational']->only(['curve_role', 'status', 'rows_count', 'updated_at']);

    $result = externalDecisions()->write(
        $scenario['validation'],
        PuExternalValidationDecision::Reject,
        (string) $scenario['reviewer']->id,
        'O gabarito externo divergiu em 12 datas sem justificativa contratual.',
    );
    $candidate = $scenario['candidate']->fresh();

    expect($result->action)->toBe(PuCandidateExternalValidationService::ACTION_REJECTED)
        ->and($result->writes)->toBe(1)
        ->and($scenario['validation']->fresh()->status)->toBe(PuCurveExternalValidationStatus::Rejected)
        ->and($scenario['validation']->fresh()->review_reason)
        ->toBe('O gabarito externo divergiu em 12 datas sem justificativa contratual.')
        ->and($candidate->external_validation_status)->toBe(PuCurveExternalValidationStatus::Rejected)
        ->and($candidate->curve_role)->toBe(PuCurveRole::Candidate)
        ->and($candidate->review_status)->toBe(PuCurveReviewStatus::Approved)
        ->and($candidate->internal_validation_status)->toBe(PuCurveInternalValidationStatus::Passed)
        ->and($scenario['operational']->fresh()->only(['curve_role', 'status', 'rows_count', 'updated_at']))
        ->toEqual($operationalBefore);
});

// ---------------------------------------------------------------------------
// Finalidade e idempotência
// ---------------------------------------------------------------------------

it('is idempotent for a repeated final decision', function (PuExternalValidationDecision $decision, string $expectedAction) {
    $scenario = externalDecisionScenario();
    $reason = 'Decisão registrada pelo revisor independente.';

    externalDecisions()->write($scenario['validation'], $decision, (string) $scenario['reviewer']->id, $reason);
    $afterFirst = PuCandidateGovernanceFixture::counts();
    $auditAfterFirst = Activity::query()
        ->whereIn('description', [
            'pu_candidate_external_validation_validated',
            'pu_candidate_external_validation_rejected',
        ])
        ->count();
    $reviewedAt = $scenario['validation']->fresh()->reviewed_at;

    $second = externalDecisions()->write(
        $scenario['validation']->fresh(),
        $decision,
        (string) $scenario['reviewer']->id,
        $reason,
    );

    expect($second->action)->toBe($expectedAction)
        ->and($second->writes)->toBe(0)
        ->and($scenario['validation']->fresh()->reviewed_at->equalTo($reviewedAt))->toBeTrue()
        ->and(PuCandidateGovernanceFixture::counts())->toBe($afterFirst)
        ->and(Activity::query()
            ->whereIn('description', [
                'pu_candidate_external_validation_validated',
                'pu_candidate_external_validation_rejected',
            ])
            ->count())->toBe($auditAfterFirst);
})->with([
    'validate twice' => [PuExternalValidationDecision::Validate, PuCandidateExternalValidationService::ACTION_ALREADY_VALIDATED],
    'reject twice' => [PuExternalValidationDecision::Reject, PuCandidateExternalValidationService::ACTION_ALREADY_REJECTED],
]);

it('refuses the opposite decision once a dossier is final', function (
    PuExternalValidationDecision $first,
    PuExternalValidationDecision $opposite,
    PuCurveExternalValidationStatus $expectedStatus,
) {
    $scenario = externalDecisionScenario();
    $reason = 'Decisão registrada pelo revisor independente.';

    externalDecisions()->write($scenario['validation'], $first, (string) $scenario['reviewer']->id, $reason);
    $afterFirst = PuCandidateGovernanceFixture::counts();

    $conflict = externalDecisions()->write(
        $scenario['validation']->fresh(),
        $opposite,
        (string) $scenario['reviewer']->id,
        $reason,
    );

    expect($conflict->action)->toBe(PuCandidateExternalValidationService::ACTION_REVIEW_CONFLICT)
        ->and($conflict->writes)->toBe(0)
        ->and($scenario['validation']->fresh()->status)->toBe($expectedStatus)
        ->and($scenario['candidate']->fresh()->external_validation_status)->toBe($expectedStatus)
        ->and(PuCandidateGovernanceFixture::counts())->toBe($afterFirst);
})->with([
    'validated then reject' => [
        PuExternalValidationDecision::Validate,
        PuExternalValidationDecision::Reject,
        PuCurveExternalValidationStatus::Validated,
    ],
    'rejected then validate' => [
        PuExternalValidationDecision::Reject,
        PuExternalValidationDecision::Validate,
        PuCurveExternalValidationStatus::Rejected,
    ],
]);

it('keeps the curve version and the dossier status consistent after the decision', function () {
    $scenario = externalDecisionScenario();

    externalDecisions()->write(
        $scenario['validation'],
        PuExternalValidationDecision::Validate,
        (string) $scenario['reviewer']->id,
    );

    expect($scenario['candidate']->fresh()->external_validation_status->value)
        ->toBe($scenario['validation']->fresh()->status->value);
});

// ---------------------------------------------------------------------------
// Matriz de autorização e independência do revisor
// ---------------------------------------------------------------------------

it('refuses a missing, unknown, inactive, unapproved or unauthorized reviewer', function (
    string $state,
    string $expectedAction,
) {
    $scenario = externalDecisionScenario();
    $identifier = match ($state) {
        'missing' => null,
        'unknown' => '9999999',
        'inactive' => (string) tap(
            PuCandidateGovernanceFixture::externalReviewer(),
            fn (User $user) => $user->forceFill(['is_active' => false])->save(),
        )->id,
        'unapproved' => (string) tap(
            PuCandidateGovernanceFixture::externalReviewer(),
            fn (User $user) => $user->forceFill(['approved_at' => null])->save(),
        )->id,
        default => (string) PuCandidateGovernanceFixture::actor([])->id,
    };
    $before = PuCandidateGovernanceFixture::counts();

    $result = externalDecisions()->write(
        $scenario['validation'],
        PuExternalValidationDecision::Validate,
        $identifier,
    );

    expect($result->action)->toBe($expectedAction)
        ->and($result->writes)->toBe(0)
        ->and($scenario['validation']->fresh()->status)->toBe(PuCurveExternalValidationStatus::Pending)
        ->and($scenario['candidate']->fresh()->external_validation_status)
        ->toBe(PuCurveExternalValidationStatus::Pending)
        ->and(PuCandidateGovernanceFixture::counts())->toBe($before);
})->with([
    'missing reviewer' => ['missing', PuCandidateExternalValidationService::ACTION_REVIEWER_REQUIRED],
    'unknown reviewer' => ['unknown', PuCandidateExternalValidationService::ACTION_REVIEWER_NOT_FOUND],
    'inactive reviewer' => ['inactive', PuCandidateExternalValidationService::ACTION_REVIEWER_INACTIVE],
    'unapproved reviewer' => ['unapproved', PuCandidateExternalValidationService::ACTION_REVIEWER_UNAPPROVED],
    'unauthorized reviewer' => ['unauthorized', PuCandidateExternalValidationService::ACTION_REVIEWER_UNAUTHORIZED],
]);

it('refuses a reviewer who is the candidate maker or the internal reviewer', function (string $role) {
    $scenario = externalDecisionScenario();
    // Independência pressupõe autorização: sem pu.curve.homologate o service
    // corretamente retorna reviewer_unauthorized antes da checagem de
    // segregação de função.
    $reviewer = PuCandidateGovernanceFixture::authorizeExternalReviewer($scenario[$role]);
    $before = PuCandidateGovernanceFixture::counts();

    $result = externalDecisions()->write(
        $scenario['validation'],
        PuExternalValidationDecision::Validate,
        (string) $reviewer->id,
    );

    expect($result->action)->toBe(PuCandidateExternalValidationService::ACTION_INDEPENDENCE_VIOLATION)
        ->and($result->writes)->toBe(0)
        ->and($scenario['validation']->fresh()->status)->toBe(PuCurveExternalValidationStatus::Pending)
        ->and($scenario['candidate']->fresh()->external_validation_status)
        ->toBe(PuCurveExternalValidationStatus::Pending)
        ->and(PuCandidateGovernanceFixture::counts())->toBe($before);
})->with([
    'reviewer is the candidate maker' => 'maker',
    'reviewer is the internal reviewer' => 'checker',
]);

it('accepts an independent reviewer who is neither the maker nor the internal reviewer', function () {
    $scenario = externalDecisionScenario();

    $result = externalDecisions()->write(
        $scenario['validation'],
        PuExternalValidationDecision::Validate,
        (string) $scenario['reviewer']->id,
    );

    expect($result->action)->toBe(PuCandidateExternalValidationService::ACTION_VALIDATED)
        ->and($result->reviewerId)->toBe($scenario['reviewer']->id)
        ->and($scenario['reviewer']->id)->not->toBe($scenario['maker']->id)
        ->and($scenario['reviewer']->id)->not->toBe($scenario['checker']->id);
});

// ---------------------------------------------------------------------------
// Integridade antes da decisão
// ---------------------------------------------------------------------------

it('blocks the decision when the candidate checksum no longer matches', function () {
    $scenario = externalDecisionScenario();
    DB::table('emission_pu_curve_versions')
        ->where('id', $scenario['candidate']->id)
        ->update(['curve_checksum' => hash('sha256', 'corrupted candidate')]);
    $before = PuCandidateGovernanceFixture::counts();

    $result = externalDecisions()->write(
        $scenario['validation'],
        PuExternalValidationDecision::Validate,
        (string) $scenario['reviewer']->id,
    );

    expect($result->action)->toBe(PuCandidateExternalValidationService::ACTION_INTEGRITY_FAILURE)
        ->and($result->writes)->toBe(0)
        ->and($scenario['validation']->fresh()->status)->toBe(PuCurveExternalValidationStatus::Pending)
        ->and(PuCandidateGovernanceFixture::counts())->toBe($before);
});

it('blocks the decision when the benchmark dataset checksum no longer matches', function () {
    $scenario = externalDecisionScenario();
    $benchmark = $scenario['validation']->benchmark;
    DB::table('emission_pu_external_benchmark_rows')
        ->where('id', $benchmark->rows()->orderBy('id')->value('id'))
        ->update(['unit_value' => '1.0000000000000000']);
    $before = PuCandidateGovernanceFixture::counts();

    $result = externalDecisions()->write(
        $scenario['validation']->fresh(),
        PuExternalValidationDecision::Validate,
        (string) $scenario['reviewer']->id,
    );

    expect($result->action)->toBe(PuCandidateExternalValidationService::ACTION_INTEGRITY_FAILURE)
        ->and($result->writes)->toBe(0)
        ->and($scenario['candidate']->fresh()->external_validation_status)
        ->toBe(PuCurveExternalValidationStatus::Pending)
        ->and(PuCandidateGovernanceFixture::counts())->toBe($before);
});

it('blocks the decision when the persisted comparison checksum no longer matches', function () {
    $scenario = externalDecisionScenario();
    DB::table('emission_pu_external_validations')
        ->where('id', $scenario['validation']->id)
        ->update(['comparison_sha256' => hash('sha256', 'corrupted comparison')]);
    $before = PuCandidateGovernanceFixture::counts();

    $result = externalDecisions()->write(
        $scenario['validation']->fresh(),
        PuExternalValidationDecision::Validate,
        (string) $scenario['reviewer']->id,
    );

    expect($result->action)->toBe(PuCandidateExternalValidationService::ACTION_INTEGRITY_FAILURE)
        ->and($result->writes)->toBe(0)
        ->and($scenario['validation']->fresh()->status)->toBe(PuCurveExternalValidationStatus::Pending)
        ->and(PuCandidateGovernanceFixture::counts())->toBe($before);
});

it('blocks the decision when a comparison row was removed behind the immutability guard', function () {
    $scenario = externalDecisionScenario();
    DB::table('emission_pu_external_validation_rows')
        ->where('id', $scenario['validation']->rows()->orderBy('id')->value('id'))
        ->delete();

    $result = externalDecisions()->write(
        $scenario['validation']->fresh(),
        PuExternalValidationDecision::Validate,
        (string) $scenario['reviewer']->id,
    );

    expect($result->action)->toBe(PuCandidateExternalValidationService::ACTION_INTEGRITY_FAILURE)
        ->and($result->writes)->toBe(0);
});

it('re-reads the dossier state instead of trusting a stale in-memory instance', function () {
    $scenario = externalDecisionScenario();
    $stale = $scenario['validation'];

    // A concurrent actor invalidates the candidate after this dossier was read.
    DB::table('emission_pu_curve_versions')
        ->where('id', $scenario['candidate']->id)
        ->update(['review_status' => PuCurveReviewStatus::PendingReview->value]);
    $before = PuCandidateGovernanceFixture::counts();

    $result = externalDecisions()->write(
        $stale,
        PuExternalValidationDecision::Validate,
        (string) $scenario['reviewer']->id,
    );

    expect($result->action)->toBe(PuCandidateExternalValidationService::ACTION_NOT_READY)
        ->and($result->writes)->toBe(0)
        ->and($stale->fresh()->status)->toBe(PuCurveExternalValidationStatus::Pending)
        ->and(PuCandidateGovernanceFixture::counts())->toBe($before);
});

it('re-reads a decision recorded concurrently instead of overwriting it', function () {
    $scenario = externalDecisionScenario();
    $stale = $scenario['validation'];

    externalDecisions()->write(
        $stale->fresh(),
        PuExternalValidationDecision::Validate,
        (string) $scenario['reviewer']->id,
    );
    $afterConcurrent = PuCandidateGovernanceFixture::counts();

    $result = externalDecisions()->write(
        $stale,
        PuExternalValidationDecision::Reject,
        (string) $scenario['reviewer']->id,
        'Tentativa tardia de rejeição.',
    );

    expect($result->action)->toBe(PuCandidateExternalValidationService::ACTION_REVIEW_CONFLICT)
        ->and($result->writes)->toBe(0)
        ->and($stale->fresh()->status)->toBe(PuCurveExternalValidationStatus::Validated)
        ->and(PuCandidateGovernanceFixture::counts())->toBe($afterConcurrent);
});

// ---------------------------------------------------------------------------
// Guard do model da candidate
// ---------------------------------------------------------------------------

it('allows only the external validation status to change on an approved candidate', function () {
    $scenario = externalDecisionScenario();
    $candidate = $scenario['candidate'];

    expect(EmissionPuCurveVersion::EXTERNAL_VALIDATION_MUTABLE_FIELDS)
        ->toBe(['external_validation_status', 'updated_at'])
        // Bundling any other field with the decision falls back to the 2B.5.16 guard.
        ->and(fn () => $candidate->fresh()->update([
            'external_validation_status' => PuCurveExternalValidationStatus::Validated,
            'curve_role' => PuCurveRole::Operational,
        ]))->toThrow(LogicException::class)
        ->and(fn () => $candidate->fresh()->update(['curve_checksum' => hash('sha256', 'other')]))
        ->toThrow(LogicException::class)
        ->and(fn () => $candidate->fresh()->update(['review_status' => PuCurveReviewStatus::PendingReview]))
        ->toThrow(LogicException::class)
        ->and($candidate->fresh()->curve_role)->toBe(PuCurveRole::Candidate);

    $fresh = $candidate->fresh();
    $fresh->update(['external_validation_status' => PuCurveExternalValidationStatus::Validated]);

    expect($fresh->fresh()->external_validation_status)
        ->toBe(PuCurveExternalValidationStatus::Validated)
        ->and($fresh->fresh()->curve_role)->toBe(PuCurveRole::Candidate)
        ->and($fresh->fresh()->review_status)->toBe(PuCurveReviewStatus::Approved);
});

// ---------------------------------------------------------------------------
// Auditoria
// ---------------------------------------------------------------------------

it('audits a validation decision under its own event name', function () {
    $scenario = externalDecisionScenario();

    externalDecisions()->write(
        $scenario['validation'],
        PuExternalValidationDecision::Validate,
        (string) $scenario['reviewer']->id,
        'Confere com o gabarito independente.',
    );
    $activity = Activity::query()
        ->where('description', 'pu_candidate_external_validation_validated')
        ->latest('id')
        ->firstOrFail();
    $properties = $activity->properties->all();

    expect($activity->causer_id)->toBe($scenario['reviewer']->id)
        ->and($properties['decision'])->toBe(PuExternalValidationDecision::Validate->value)
        ->and($properties['candidate_version_id'])->toBe($scenario['candidate']->id)
        ->and($properties['external_validation_id'])->toBe($scenario['validation']->id)
        ->and($properties['comparison_sha256'])->toBe($scenario['validation']->comparison_sha256)
        ->and($properties['reason'])->toBe('Confere com o gabarito independente.')
        ->and(Activity::query()->where('description', 'pu_candidate_external_validation_rejected')->count())
        ->toBe(0);
});

it('audits a rejection decision under its own event name', function () {
    $scenario = externalDecisionScenario();

    externalDecisions()->write(
        $scenario['validation'],
        PuExternalValidationDecision::Reject,
        (string) $scenario['reviewer']->id,
        'Divergência não explicada.',
    );

    expect(Activity::query()->where('description', 'pu_candidate_external_validation_rejected')->count())
        ->toBe(1)
        ->and(Activity::query()->where('description', 'pu_candidate_external_validation_validated')->count())
        ->toBe(0)
        ->and(Activity::query()->where('description', 'pu_candidate_external_validation_rejectd')->count())
        ->toBe(0);
});

// ---------------------------------------------------------------------------
// Isolamento financeiro e operacional do ciclo completo
// ---------------------------------------------------------------------------

it('completes the whole external validation cycle with zero financial side effects', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $operational = PuCandidateGovernanceFixture::operationalCurve($emission);
    $maker = PuCandidateGovernanceFixture::maker();
    $checker = PuCandidateGovernanceFixture::checker();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission, $maker, $checker);
    $before = PuCandidateGovernanceFixture::counts();
    $operationalBefore = $operational->fresh()->only(['curve_role', 'status', 'rows_count', 'updated_at']);
    $operationalRowsBefore = $operational->dailyCurves()->pluck('updated_unit_value')->all();

    $benchmark = PuCandidateGovernanceFixture::persistedExternalBenchmark($emission, externalDecisionValues());
    $validation = PuCandidateGovernanceFixture::persistedExternalComparison($candidate, $benchmark);
    externalDecisions()->write(
        $validation,
        PuExternalValidationDecision::Validate,
        (string) PuCandidateGovernanceFixture::externalReviewer()->id,
        'Ciclo completo homologado externamente.',
    );
    $after = PuCandidateGovernanceFixture::counts();

    expect($after['rates'])->toBe($before['rates'])
        ->and($after['events'])->toBe($before['events'])
        ->and($after['parameters'])->toBe($before['parameters'])
        ->and($after['integralizations'])->toBe($before['integralizations'])
        ->and($after['histories'])->toBe($before['histories'])
        ->and($after['payments'])->toBe($before['payments'])
        ->and($after['daily_curves'])->toBe($before['daily_curves'])
        ->and($after['operational_versions'])->toBe($before['operational_versions'])
        ->and($after['candidate_versions'])->toBe($before['candidate_versions'])
        ->and($operational->fresh()->only(['curve_role', 'status', 'rows_count', 'updated_at']))
        ->toEqual($operationalBefore)
        ->and($operational->dailyCurves()->pluck('updated_unit_value')->all())->toBe($operationalRowsBefore)
        ->and($operational->fresh()->status)->not->toBe(PuCurveStatus::Obsolete)
        ->and($candidate->fresh()->curve_role)->toBe(PuCurveRole::Candidate);
});

// ---------------------------------------------------------------------------
// Dossiê
// ---------------------------------------------------------------------------

it('presents the externally validated candidate as a candidate and never as operational', function () {
    $scenario = externalDecisionScenario();
    externalDecisions()->write(
        $scenario['validation'],
        PuExternalValidationDecision::Validate,
        (string) $scenario['reviewer']->id,
        'Confere com o gabarito independente.',
    );

    $dossier = app(PuHomologationReportService::class)->build($scenario['candidate']->fresh());

    expect($dossier['version']['curve_role'])->toBe(PuCurveRole::Candidate->value)
        ->and($dossier['version']['internal_validation_status'])
        ->toBe(PuCurveInternalValidationStatus::Passed->value)
        ->and($dossier['version']['review_status'])->toBe(PuCurveReviewStatus::Approved->value)
        ->and($dossier['version']['external_validation_status'])
        ->toBe(PuCurveExternalValidationStatus::Validated->value)
        ->and($dossier['external_validation']['has_comparison'])->toBeTrue()
        ->and($dossier['external_validation']['status'])
        ->toBe(PuCurveExternalValidationStatus::Validated->value)
        ->and($dossier['external_validation']['coverage_status'])->toBe('full')
        ->and($dossier['external_validation']['compared_rows'])->toBe(3)
        ->and($dossier['external_validation']['benchmark']['dataset_sha256'])
        ->toBe($scenario['validation']->benchmark->dataset_sha256)
        ->and($dossier['external_validation']['tolerance_policy'])->toBeNull()
        ->and($dossier['external_validation']['reviewed_by'])->toBe($scenario['reviewer']->name)
        ->and($dossier['version']['curve_role'])->not->toBe(PuCurveRole::Operational->value)
        ->and($dossier['version']['homologated_at'])->toBeNull();
});

it('caps the rendered difference sample instead of printing an unbounded table', function () {
    $scenario = externalDecisionScenario();

    $dossier = app(PuHomologationReportService::class)->build($scenario['candidate']);

    expect($dossier['external_validation']['difference_sample_limit'])->toBe(25)
        ->and($dossier['external_validation']['differences'])
        ->toHaveCount(min(25, $scenario['validation']->compared_rows));
});

it('reports no external comparison for a candidate that has none', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);

    $dossier = app(PuHomologationReportService::class)->build($candidate);

    expect($dossier['external_validation']['has_comparison'])->toBeFalse()
        ->and($dossier['external_validation']['differences'])->toBe([])
        ->and($dossier['version']['external_validation_status'])
        ->toBe(PuCurveExternalValidationStatus::Pending->value);
});

// ---------------------------------------------------------------------------
// Comandos
// ---------------------------------------------------------------------------

it('requires exactly one of --validate or --reject', function (array $options) {
    $scenario = externalDecisionScenario();

    $this->artisan('pu:curve-candidate:review-external-validation', [
        'validation' => $scenario['validation']->id,
        ...$options,
    ])->assertFailed();

    expect($scenario['validation']->fresh()->status)->toBe(PuCurveExternalValidationStatus::Pending);
})->with([
    'no decision' => [[]],
    'both decisions' => [['--validate' => true, '--reject' => true]],
]);

it('runs the review command read-only by default', function () {
    $scenario = externalDecisionScenario();
    $before = PuCandidateGovernanceFixture::counts();

    $this->artisan('pu:curve-candidate:review-external-validation', [
        'validation' => $scenario['validation']->id,
        '--validate' => true,
        '--reviewer' => (string) $scenario['reviewer']->id,
    ])
        ->expectsOutputToContain('Action: '.PuCandidateExternalValidationService::ACTION_READY)
        ->expectsOutputToContain('Writes: 0')
        ->assertSuccessful();

    expect($scenario['validation']->fresh()->status)->toBe(PuCurveExternalValidationStatus::Pending)
        ->and(PuCandidateGovernanceFixture::counts())->toBe($before);
});

it('lets dry-run win over write in the review command', function () {
    $scenario = externalDecisionScenario();

    $this->artisan('pu:curve-candidate:review-external-validation', [
        'validation' => $scenario['validation']->id,
        '--validate' => true,
        '--reviewer' => (string) $scenario['reviewer']->id,
        '--dry-run' => true,
        '--write' => true,
    ])
        ->expectsOutputToContain('Writes: 0')
        ->assertSuccessful();

    expect($scenario['validation']->fresh()->status)->toBe(PuCurveExternalValidationStatus::Pending);
});

it('records the decision through the review command only with a reviewer and --write', function () {
    $scenario = externalDecisionScenario();

    $this->artisan('pu:curve-candidate:review-external-validation', [
        'validation' => $scenario['validation']->id,
        '--validate' => true,
        '--write' => true,
    ])
        ->expectsOutputToContain('Action: '.PuCandidateExternalValidationService::ACTION_REVIEWER_REQUIRED)
        ->assertFailed();

    expect($scenario['validation']->fresh()->status)->toBe(PuCurveExternalValidationStatus::Pending);

    $this->artisan('pu:curve-candidate:review-external-validation', [
        'validation' => $scenario['validation']->id,
        '--validate' => true,
        '--reviewer' => (string) $scenario['reviewer']->id,
        '--write' => true,
    ])
        ->expectsOutputToContain('Action: '.PuCandidateExternalValidationService::ACTION_VALIDATED)
        ->expectsOutputToContain('Writes: 1')
        ->assertSuccessful();

    expect($scenario['validation']->fresh()->status)->toBe(PuCurveExternalValidationStatus::Validated)
        ->and($scenario['candidate']->fresh()->curve_role)->toBe(PuCurveRole::Candidate);
});

it('reports the real Alto Bellevue state as not ready with zero writes', function () {
    PuCandidateGovernanceFixture::emission();
    $before = PuCandidateGovernanceFixture::counts();

    $this->artisan('pu:alto-bellevue:external-validation-status')
        ->expectsOutputToContain('Candidate: none')
        ->expectsOutputToContain('External validation: not ready')
        ->expectsOutputToContain('Action: external_validation_not_ready')
        ->expectsOutputToContain('Writes: 0')
        ->expectsOutputToContain('No benchmark parsed, no comparison generated and no operational effect occurred.')
        ->assertSuccessful();

    expect(PuCandidateGovernanceFixture::counts())->toBe($before)
        ->and(EmissionPuExternalValidation::query()->count())->toBe(0);
});

it('keeps the Alto Bellevue status command read-only even with an approved candidate', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);
    $before = PuCandidateGovernanceFixture::counts();

    $this->artisan('pu:alto-bellevue:external-validation-status')
        ->expectsOutputToContain('Candidate: #'.$candidate->id)
        ->expectsOutputToContain('External validation: ready')
        ->expectsOutputToContain('Writes: 0')
        ->assertSuccessful();

    expect(PuCandidateGovernanceFixture::counts())->toBe($before)
        ->and($candidate->fresh()->external_validation_status)->toBe(PuCurveExternalValidationStatus::Pending);
});
