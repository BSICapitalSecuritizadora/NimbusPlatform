<?php

use App\Domain\PuCalculator\Enums\PuCurveExternalValidationStatus;
use App\Domain\PuCalculator\Enums\PuCurveInternalValidationStatus;
use App\Domain\PuCalculator\Enums\PuCurvePromotionDecision;
use App\Domain\PuCalculator\Enums\PuCurvePromotionStatus;
use App\Domain\PuCalculator\Enums\PuCurveReviewStatus;
use App\Domain\PuCalculator\Enums\PuCurveRole;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Services\PuCurveExportService;
use App\Domain\PuCalculator\Services\PuCurveOperationalPromotionService;
use App\Domain\PuCalculator\Services\PuCurvePromotionRequestService;
use App\Domain\PuCalculator\Services\PuCurvePromotionReviewService;
use App\Domain\PuCalculator\Services\PuHomologationReportService;
use App\Domain\PuCalculator\Services\PuOperationalMonitorService;
use App\Models\Emission;
use App\Models\EmissionPuCurvePromotion;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuExternalBenchmark;
use App\Models\EmissionPuExternalValidation;
use App\Models\IndexRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Pu\PuCandidateGovernanceFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    // Nenhuma chamada externa é esperada nesta fase: qualquer tentativa de
    // fetch estoura em vez de passar silenciosamente.
    Http::preventStrayRequests();
});

/**
 * Dobre que força uma falha ENTRE a obsolescência da operacional anterior e a
 * ativação da candidate, provando que a transação reverte o switch por inteiro.
 *
 * Segue o mesmo precedente já adotado nas fases anteriores: a classe de
 * produção é aberta para extensão exclusivamente para permitir este cenário, e
 * o dobre vive só no teste.
 */
class PuPromotionActivationFailureSpy extends PuCurveOperationalPromotionService
{
    public bool $supersedeRan = false;

    protected function supersedePreviousOperational(?EmissionPuCurveVersion $previous): void
    {
        parent::supersedePreviousOperational($previous);
        $this->supersedeRan = true;
    }

    protected function activateCandidate(EmissionPuCurveVersion $candidate): void
    {
        throw new RuntimeException('Synthetic failure between obsolescence and activation.');
    }
}

function operationalPromotions(): PuCurveOperationalPromotionService
{
    return app(PuCurveOperationalPromotionService::class);
}

/**
 * Linha bruta do benchmark imutável e do dossiê de comparação, direto do banco:
 * qualquer escrita da promoção nesses artefatos apareceria aqui, inclusive um
 * `updated_at` tocado por engano.
 *
 * @return array<string, mixed>
 */
function externalArtifactSnapshot(int $benchmarkId, int $validationId): array
{
    return [
        'benchmark' => (array) DB::table('emission_pu_external_benchmarks')->find($benchmarkId),
        'benchmark_rows' => DB::table('emission_pu_external_benchmark_rows')
            ->where('benchmark_id', $benchmarkId)
            ->orderBy('id')
            ->get()
            ->map(fn ($row): array => (array) $row)
            ->all(),
        'validation' => (array) DB::table('emission_pu_external_validations')->find($validationId),
        'validation_rows' => DB::table('emission_pu_external_validation_rows')
            ->where('external_validation_id', $validationId)
            ->orderBy('id')
            ->get()
            ->map(fn ($row): array => (array) $row)
            ->all(),
        'validation_gaps' => DB::table('emission_pu_external_validation_gaps')
            ->where('external_validation_id', $validationId)
            ->orderBy('id')
            ->get()
            ->map(fn ($row): array => (array) $row)
            ->all(),
    ];
}

/**
 * Cenário completo: operacional A vigente, candidate B validada externamente,
 * pedido R aprovado por um revisor independente e pronto para execução.
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
 *     executor:User,
 * }
 */
function approvedPromotionScenario(bool $approve = true): array
{
    $emission = PuCandidateGovernanceFixture::emission();
    $operational = PuCandidateGovernanceFixture::operationalCurve($emission);
    $governed = PuCandidateGovernanceFixture::externallyValidatedCandidate($emission);
    $requester = PuCandidateGovernanceFixture::promotionRequester();
    $reviewer = PuCandidateGovernanceFixture::promotionReviewer();
    $request = app(PuCurvePromotionRequestService::class)->write(
        $emission->fresh(),
        $governed['candidate'],
        (string) $requester->id,
    );

    if ($request->action !== PuCurvePromotionRequestService::ACTION_REQUESTED) {
        throw new RuntimeException(sprintf('Expected a promotion request; got %s.', $request->action));
    }

    $promotion = EmissionPuCurvePromotion::query()->findOrFail($request->promotionId);

    if ($approve) {
        $decision = app(PuCurvePromotionReviewService::class)->write(
            $promotion,
            PuCurvePromotionDecision::Approve,
            (string) $reviewer->id,
        );

        if ($decision->action !== PuCurvePromotionReviewService::ACTION_APPROVED) {
            throw new RuntimeException(sprintf('Expected an approved promotion; got %s.', $decision->action));
        }
    }

    return [
        'emission' => $emission->fresh(),
        'operational' => $operational->fresh(),
        ...$governed,
        'promotion' => $promotion->fresh(),
        'requester' => $requester,
        'reviewer' => $reviewer,
        'executor' => PuCandidateGovernanceFixture::promotionExecutor(),
    ];
}

// ---------------------------------------------------------------------------
// Elegibilidade de execução
// ---------------------------------------------------------------------------

it('refuses to execute a promotion that is still pending review', function () {
    $scenario = approvedPromotionScenario(approve: false);
    $before = PuCandidateGovernanceFixture::promotionCounts();

    $result = operationalPromotions()->write($scenario['promotion'], (string) $scenario['executor']->id);

    expect($result->action)->toBe(PuCurveOperationalPromotionService::ACTION_NOT_APPROVED)
        ->and($result->writes)->toBe(0)
        ->and(PuCandidateGovernanceFixture::promotionCounts())->toBe($before)
        ->and($scenario['candidate']->fresh()->curve_role)->toBe(PuCurveRole::Candidate)
        ->and($scenario['operational']->fresh()->status)->toBe(PuCurveStatus::Generated);
});

it('refuses to execute a rejected promotion', function () {
    $scenario = approvedPromotionScenario(approve: false);
    app(PuCurvePromotionReviewService::class)->write(
        $scenario['promotion'],
        PuCurvePromotionDecision::Reject,
        (string) $scenario['reviewer']->id,
        'Rejeitada.',
    );

    $result = operationalPromotions()->write($scenario['promotion']->fresh(), (string) $scenario['executor']->id);

    expect($result->action)->toBe(PuCurveOperationalPromotionService::ACTION_REJECTED)
        ->and($result->writes)->toBe(0)
        ->and($scenario['candidate']->fresh()->curve_role)->toBe(PuCurveRole::Candidate);
});

it('reports promotion_not_found when the promotion does not exist', function () {
    expect(operationalPromotions()->write(null, '1')->action)
        ->toBe(PuCurveOperationalPromotionService::ACTION_NOT_FOUND);
});

it('previews the execution without writing anything', function () {
    $scenario = approvedPromotionScenario();
    $before = PuCandidateGovernanceFixture::promotionCounts();

    $result = operationalPromotions()->inspect($scenario['promotion'], (string) $scenario['executor']->id);

    expect($result->action)->toBe(PuCurveOperationalPromotionService::ACTION_READY)
        ->and($result->writes)->toBe(0)
        ->and(PuCandidateGovernanceFixture::promotionCounts())->toBe($before)
        ->and($scenario['candidate']->fresh()->curve_role)->toBe(PuCurveRole::Candidate);
});

// ---------------------------------------------------------------------------
// Switch atômico bem-sucedido
// ---------------------------------------------------------------------------

it('promotes the externally validated candidate and supersedes the previous operational version', function () {
    $scenario = approvedPromotionScenario();

    $result = operationalPromotions()->write($scenario['promotion'], (string) $scenario['executor']->id);

    $candidate = $scenario['candidate']->fresh();
    $previous = $scenario['operational']->fresh();
    $promotion = $scenario['promotion']->fresh();

    expect($result->action)->toBe(PuCurveOperationalPromotionService::ACTION_PROMOTED)
        ->and($result->newOperationalVersionId)->toBe($candidate->id)
        ->and($result->previousOperationalVersionId)->toBe($previous->id)
        ->and($candidate->curve_role)->toBe(PuCurveRole::Operational)
        ->and($previous->curve_role)->toBe(PuCurveRole::Operational)
        ->and($previous->status)->toBe(PuCurveStatus::Obsolete)
        ->and($previous->obsolete_reason)->toBe(PuCurveOperationalPromotionService::OBSOLETE_REASON)
        ->and($promotion->status)->toBe(PuCurvePromotionStatus::Executed)
        ->and($promotion->executed_by)->toBe($scenario['executor']->id)
        ->and($promotion->promoted_at)->not->toBeNull();
});

it('preserves the entire financial identity of the promoted version', function () {
    $scenario = approvedPromotionScenario();
    $before = PuCandidateGovernanceFixture::curveIdentitySnapshot($scenario['candidate']);

    operationalPromotions()->write($scenario['promotion'], (string) $scenario['executor']->id);

    $after = PuCandidateGovernanceFixture::curveIdentitySnapshot($scenario['candidate']);
    $candidate = $scenario['candidate']->fresh();

    expect($after)->toBe($before)
        ->and($candidate->curve_checksum)->toBe($scenario['candidate']->curve_checksum)
        ->and($candidate->input_fingerprint)->toBe($scenario['candidate']->input_fingerprint)
        ->and($candidate->rows_count)->toBe($scenario['candidate']->rows_count)
        ->and($candidate->calculation_version)->toBe($scenario['candidate']->calculation_version)
        ->and($candidate->internal_validation_status)->toBe(PuCurveInternalValidationStatus::Passed)
        ->and($candidate->review_status)->toBe(PuCurveReviewStatus::Approved)
        ->and($candidate->reviewed_by)->toBe($scenario['internalReviewer']->id)
        ->and($candidate->external_validation_status)->toBe(PuCurveExternalValidationStatus::Validated)
        ->and($candidate->status)->toBe(PuCurveStatus::Validated);
});

it('rewrites no daily curve row and creates no new one', function () {
    $scenario = approvedPromotionScenario();
    $rowsBefore = PuCandidateGovernanceFixture::dailyRowSnapshot($scenario['candidate']);
    $totalRowsBefore = EmissionPuDailyCurve::query()->count();
    $totalVersionsBefore = EmissionPuCurveVersion::query()->count();

    operationalPromotions()->write($scenario['promotion'], (string) $scenario['executor']->id);

    expect(PuCandidateGovernanceFixture::dailyRowSnapshot($scenario['candidate']))->toBe($rowsBefore)
        ->and(EmissionPuDailyCurve::query()->count())->toBe($totalRowsBefore)
        ->and(EmissionPuCurveVersion::query()->count())->toBe($totalVersionsBefore);
});

it('creates no new calculation version', function () {
    $scenario = approvedPromotionScenario();
    $versionsBefore = EmissionPuCurveVersion::query()
        ->whereBelongsTo($scenario['emission'])
        ->orderBy('id')
        ->pluck('calculation_version')
        ->all();

    operationalPromotions()->write($scenario['promotion'], (string) $scenario['executor']->id);

    expect(EmissionPuCurveVersion::query()
        ->whereBelongsTo($scenario['emission'])
        ->orderBy('id')
        ->pluck('calculation_version')
        ->all())->toBe($versionsBefore);
});

it('leaves sibling, rejected and other governed candidates untouched', function () {
    $scenario = approvedPromotionScenario();
    // Cada versão sintética é uma geração financeira distinta e por isso carrega
    // a sua própria `calculation_version`: duas versões da mesma emissão não
    // podem compartilhar identidade de cálculo sobre as mesmas datas.
    $sibling = PuCandidateGovernanceFixture::approvedCandidate(
        $scenario['emission'],
        calculationVersion: 'sibling-candidate',
    );
    $rejected = EmissionPuCurveVersion::factory()->candidate()->create([
        'emission_id' => $scenario['emission']->id,
        'calculation_version' => 'rejected-sibling',
        'review_status' => PuCurveReviewStatus::Rejected,
        'reviewed_at' => now(),
        'rows_count' => 1,
    ]);
    $siblingBefore = PuCandidateGovernanceFixture::curveIdentitySnapshot($sibling);
    $rejectedBefore = PuCandidateGovernanceFixture::curveIdentitySnapshot($rejected);

    operationalPromotions()->write($scenario['promotion'], (string) $scenario['executor']->id);

    expect($sibling->fresh()->curve_role)->toBe(PuCurveRole::Candidate)
        ->and($sibling->fresh()->status)->not->toBe(PuCurveStatus::Obsolete)
        ->and($rejected->fresh()->curve_role)->toBe(PuCurveRole::Candidate)
        ->and($rejected->fresh()->status)->not->toBe(PuCurveStatus::Obsolete)
        ->and(PuCandidateGovernanceFixture::curveIdentitySnapshot($sibling))->toBe($siblingBefore)
        ->and(PuCandidateGovernanceFixture::curveIdentitySnapshot($rejected))->toBe($rejectedBefore)
        ->and(EmissionPuCurveVersion::query()
            ->candidate()
            ->where('status', PuCurveStatus::Obsolete->value)
            ->count())->toBe(0);
});

it('never lets the promoted candidate obsolete itself', function () {
    $scenario = approvedPromotionScenario();

    operationalPromotions()->write($scenario['promotion'], (string) $scenario['executor']->id);

    expect($scenario['candidate']->fresh()->status)->toBe(PuCurveStatus::Validated)
        ->and($scenario['candidate']->fresh()->obsolete_reason)->toBeNull();
});

it('mutates no benchmark, comparison or external validation artifact', function () {
    $scenario = approvedPromotionScenario();
    $benchmarkBefore = externalArtifactSnapshot($scenario['benchmark']->id, $scenario['validation']->id);
    $before = PuCandidateGovernanceFixture::promotionCounts();

    operationalPromotions()->write($scenario['promotion'], (string) $scenario['executor']->id);

    $after = PuCandidateGovernanceFixture::promotionCounts();

    expect(externalArtifactSnapshot($scenario['benchmark']->id, $scenario['validation']->id))
        ->toBe($benchmarkBefore)
        ->and($scenario['validation']->fresh()->status)->toBe(PuCurveExternalValidationStatus::Validated)
        ->and($scenario['validation']->fresh()->reviewed_by)->toBe($scenario['externalReviewer']->id)
        ->and($after['external_benchmarks'])->toBe($before['external_benchmarks'])
        ->and($after['external_benchmark_rows'])->toBe($before['external_benchmark_rows'])
        ->and($after['external_validations'])->toBe($before['external_validations'])
        ->and($after['external_validation_rows'])->toBe($before['external_validation_rows'])
        ->and($after['external_validation_gaps'])->toBe($before['external_validation_gaps']);
});

it('touches no financial prerequisite, history, payment or index rate', function () {
    $scenario = approvedPromotionScenario();
    $before = PuCandidateGovernanceFixture::promotionCounts();
    $ratesBefore = IndexRate::query()->orderBy('id')->pluck('rate_value')->all();

    operationalPromotions()->write($scenario['promotion'], (string) $scenario['executor']->id);

    $after = PuCandidateGovernanceFixture::promotionCounts();

    expect($after['parameters'])->toBe($before['parameters'])
        ->and($after['rates'])->toBe($before['rates'])
        ->and($after['events'])->toBe($before['events'])
        ->and($after['integralizations'])->toBe($before['integralizations'])
        ->and($after['histories'])->toBe($before['histories'])
        ->and($after['payments'])->toBe($before['payments'])
        ->and($after['daily_curves'])->toBe($before['daily_curves'])
        ->and(IndexRate::query()->orderBy('id')->pluck('rate_value')->all())->toBe($ratesBefore);
});

// ---------------------------------------------------------------------------
// Consumidores operacionais
// ---------------------------------------------------------------------------

it('makes every operational consumer select the promoted version', function () {
    $scenario = approvedPromotionScenario();

    operationalPromotions()->write($scenario['promotion'], (string) $scenario['executor']->id);

    $emission = $scenario['emission']->fresh();
    $candidate = $scenario['candidate']->fresh();
    $exportRows = app(PuCurveExportService::class)->rows($emission);
    $exportSummary = app(PuCurveExportService::class)->summary($emission);

    expect($emission->latestPuCurveVersion()->first()?->id)->toBe($candidate->id)
        ->and($emission->currentPuCurveVersion()?->id)->toBe($candidate->id)
        ->and(EmissionPuDailyCurve::latestCalculationVersionForEmission($emission->id))
        ->toBe($candidate->calculation_version)
        ->and($exportSummary['calculation_version'])->toBe($candidate->calculation_version)
        ->and($exportRows)->toHaveCount($candidate->rows_count)
        ->and($emission->operationalPuCurveVersions()->pluck('id')->all())
        ->toContain($candidate->id)
        ->and($emission->candidatePuCurveVersions()->pluck('id')->all())
        ->not->toContain($candidate->id)
        ->and($emission->operationalPuDailyCurves()->count())
        ->toBe($candidate->rows_count + $scenario['operational']->rows_count);
});

it('shows the promoted version in the operational monitor', function () {
    $scenario = approvedPromotionScenario();

    operationalPromotions()->write($scenario['promotion'], (string) $scenario['executor']->id);

    $latestOperationalIds = EmissionPuCurveVersion::query()
        ->whereBelongsTo($scenario['emission'])
        ->operational()
        ->selectRaw('MAX(id) as id')
        ->groupBy('emission_id')
        ->pluck('id')
        ->map(fn ($id): int => (int) $id)
        ->all();
    // `recentValidations()` só olha versões operacionais: antes da promoção a
    // candidate não aparecia nela por definição.
    $recentIds = app(PuOperationalMonitorService::class)
        ->recentValidations(10)
        ->pluck('id')
        ->all();

    expect($latestOperationalIds)->toBe([$scenario['candidate']->id])
        ->and($recentIds)->toContain($scenario['candidate']->id);
});

it('keeps both versions visible in the full administrative inventory', function () {
    $scenario = approvedPromotionScenario();
    $sibling = PuCandidateGovernanceFixture::approvedCandidate(
        $scenario['emission'],
        calculationVersion: 'sibling-candidate',
    );

    operationalPromotions()->write($scenario['promotion'], (string) $scenario['executor']->id);

    $inventory = EmissionPuCurveVersion::query()
        ->whereBelongsTo($scenario['emission'])
        ->orderByDesc('id')
        ->get()
        ->mapWithKeys(fn (EmissionPuCurveVersion $version): array => [
            $version->id => [$version->curve_role->value, $version->status->value],
        ])
        ->all();

    expect($inventory[$scenario['candidate']->id])->toBe(['operational', 'validated'])
        ->and($inventory[$scenario['operational']->id])->toBe(['operational', 'obsolete'])
        ->and($inventory[$sibling->id])->toBe(['candidate', 'validated']);
});

it('keeps the full governance dossier on the promoted version report', function () {
    $scenario = approvedPromotionScenario();

    operationalPromotions()->write($scenario['promotion'], (string) $scenario['executor']->id);

    $report = app(PuHomologationReportService::class)->build($scenario['candidate']->fresh());

    expect($report['version']['curve_role'])->toBe('operational')
        ->and($report['version']['review_status'])->toBe('approved')
        ->and($report['version']['external_validation_status'])->toBe('validated')
        ->and($report['external_validation']['has_comparison'])->toBeTrue()
        ->and($report['external_validation']['status'])->toBe('validated')
        ->and($report['promotion']['has_promotion'])->toBeTrue()
        ->and($report['promotion']['status'])->toBe('executed')
        ->and($report['promotion']['previous_operational_version_id'])->toBe($scenario['operational']->id)
        ->and($report['promotion']['reviewed_by'])->toBe($scenario['reviewer']->name)
        ->and($report['promotion']['executed_by'])->toBe($scenario['executor']->name);
});

// ---------------------------------------------------------------------------
// Idempotência, concorrência e TOCTOU
// ---------------------------------------------------------------------------

it('is idempotent: a second execution reports already_executed with zero writes', function () {
    $scenario = approvedPromotionScenario();
    operationalPromotions()->write($scenario['promotion'], (string) $scenario['executor']->id);
    $before = PuCandidateGovernanceFixture::promotionCounts();
    $promotedAt = $scenario['promotion']->fresh()->promoted_at;

    $second = operationalPromotions()->write($scenario['promotion']->fresh(), (string) $scenario['executor']->id);

    expect($second->action)->toBe(PuCurveOperationalPromotionService::ACTION_ALREADY_EXECUTED)
        ->and($second->writes)->toBe(0)
        ->and(PuCandidateGovernanceFixture::promotionCounts())->toBe($before)
        ->and($scenario['promotion']->fresh()->promoted_at?->toIso8601String())
        ->toBe($promotedAt?->toIso8601String());
});

it('lets only one of two sequential executions perform the switch', function () {
    $scenario = approvedPromotionScenario();

    $results = collect([
        operationalPromotions()->write($scenario['promotion']->fresh(), (string) $scenario['executor']->id),
        operationalPromotions()->write($scenario['promotion']->fresh(), (string) $scenario['executor']->id),
    ]);

    expect($results->where('action', PuCurveOperationalPromotionService::ACTION_PROMOTED))->toHaveCount(1)
        ->and($results->where('action', PuCurveOperationalPromotionService::ACTION_ALREADY_EXECUTED))->toHaveCount(1)
        ->and($results->sum('writes'))->toBe(3)
        ->and(EmissionPuCurveVersion::query()
            ->whereBelongsTo($scenario['emission'])
            ->operational()
            ->where('status', '!=', PuCurveStatus::Obsolete->value)
            ->count())->toBe(1);
});

it('blocks the switch when the operational baseline changed after the request', function () {
    $scenario = approvedPromotionScenario();
    // Uma geração operacional genuinamente nova virou vigente entre o pedido e a
    // execução: versão de cálculo própria, id maior que a operacional capturada.
    $newerOperational = PuCandidateGovernanceFixture::operationalCurve(
        $scenario['emission'],
        calculationVersion: 'operational-v2',
    );
    $before = PuCandidateGovernanceFixture::promotionCounts();

    $result = operationalPromotions()->write($scenario['promotion']->fresh(), (string) $scenario['executor']->id);

    // `promotion_state_changed` cobre duas precondições; o `reason` fixa qual
    // delas este cenário exercita -- o baseline capturado deixou de ser o
    // vigente, e não a regra de idade da candidate.
    expect($result->action)->toBe(PuCurveOperationalPromotionService::ACTION_STATE_CHANGED)
        ->and($result->reason)->toContain('operational baseline changed')
        ->and($result->writes)->toBe(0)
        ->and(PuCandidateGovernanceFixture::promotionCounts())->toBe($before)
        ->and($scenario['candidate']->fresh()->curve_role)->toBe(PuCurveRole::Candidate)
        ->and($scenario['operational']->fresh()->status)->toBe(PuCurveStatus::Generated)
        ->and($scenario['emission']->fresh()->latestPuCurveVersion()->first()?->id)
        ->toBe($newerOperational->id)
        ->and($scenario['promotion']->fresh()->status)->toBe(PuCurvePromotionStatus::Approved);
});

it('blocks the switch when the candidate checksum changed after the approval', function () {
    $scenario = approvedPromotionScenario();
    DB::table('emission_pu_curve_versions')
        ->where('id', $scenario['candidate']->id)
        ->update(['curve_checksum' => str_repeat('a', 64)]);
    $before = PuCandidateGovernanceFixture::promotionCounts();

    $result = operationalPromotions()->write($scenario['promotion']->fresh(), (string) $scenario['executor']->id);

    expect($result->action)->toBe(PuCurveOperationalPromotionService::ACTION_INTEGRITY_FAILURE)
        ->and($result->writes)->toBe(0)
        ->and(PuCandidateGovernanceFixture::promotionCounts())->toBe($before)
        ->and($scenario['candidate']->fresh()->curve_role)->toBe(PuCurveRole::Candidate);
});

it('blocks the switch when a persisted daily row changed after the approval', function () {
    $scenario = approvedPromotionScenario();
    $row = $scenario['candidate']->dailyCurves()->orderBy('curve_date')->firstOrFail();
    DB::table('emission_pu_daily_curves')
        ->where('id', $row->id)
        ->update(['updated_unit_value' => '4242.0000000000000000']);

    $result = operationalPromotions()->write($scenario['promotion']->fresh(), (string) $scenario['executor']->id);

    expect($result->action)->toBe(PuCurveOperationalPromotionService::ACTION_INTEGRITY_FAILURE)
        ->and($scenario['candidate']->fresh()->curve_role)->toBe(PuCurveRole::Candidate);
});

it('blocks the switch when the benchmark dataset changed after the approval', function () {
    $scenario = approvedPromotionScenario();
    DB::table('emission_pu_external_benchmarks')
        ->where('id', $scenario['benchmark']->id)
        ->update(['dataset_sha256' => str_repeat('b', 64)]);

    expect(operationalPromotions()->write($scenario['promotion']->fresh(), (string) $scenario['executor']->id)->action)
        ->toBe(PuCurveOperationalPromotionService::ACTION_INTEGRITY_FAILURE)
        ->and($scenario['candidate']->fresh()->curve_role)->toBe(PuCurveRole::Candidate);
});

it('blocks the switch when the comparison checksum changed after the approval', function () {
    $scenario = approvedPromotionScenario();
    DB::table('emission_pu_external_validations')
        ->where('id', $scenario['validation']->id)
        ->update(['comparison_sha256' => str_repeat('c', 64)]);

    expect(operationalPromotions()->write($scenario['promotion']->fresh(), (string) $scenario['executor']->id)->action)
        ->toBe(PuCurveOperationalPromotionService::ACTION_INTEGRITY_FAILURE);
});

it('blocks the switch when the external validation status changed after the approval', function () {
    $scenario = approvedPromotionScenario();
    DB::table('emission_pu_external_validations')
        ->where('id', $scenario['validation']->id)
        ->update(['status' => PuCurveExternalValidationStatus::Rejected->value]);
    DB::table('emission_pu_curve_versions')
        ->where('id', $scenario['candidate']->id)
        ->update(['external_validation_status' => PuCurveExternalValidationStatus::Rejected->value]);

    $result = operationalPromotions()->write($scenario['promotion']->fresh(), (string) $scenario['executor']->id);

    expect($result->action)->toBe(PuCurveOperationalPromotionService::ACTION_STATE_CHANGED)
        ->and($result->writes)->toBe(0)
        ->and($scenario['candidate']->fresh()->curve_role)->toBe(PuCurveRole::Candidate);
});

// ---------------------------------------------------------------------------
// Rollback técnico da transação
// ---------------------------------------------------------------------------

it('rolls back the entire switch when the activation fails after the obsolescence', function () {
    $scenario = approvedPromotionScenario();
    $spy = app(PuPromotionActivationFailureSpy::class);
    $before = PuCandidateGovernanceFixture::promotionCounts();

    expect(fn () => $spy->write($scenario['promotion'], (string) $scenario['executor']->id))
        ->toThrow(RuntimeException::class);

    expect($spy->supersedeRan)->toBeTrue()
        ->and($scenario['operational']->fresh()->status)->toBe(PuCurveStatus::Generated)
        ->and($scenario['operational']->fresh()->obsolete_reason)->toBeNull()
        ->and($scenario['candidate']->fresh()->curve_role)->toBe(PuCurveRole::Candidate)
        ->and($scenario['promotion']->fresh()->status)->toBe(PuCurvePromotionStatus::Approved)
        ->and($scenario['promotion']->fresh()->promoted_at)->toBeNull()
        ->and(PuCandidateGovernanceFixture::promotionCounts())->toBe($before)
        ->and($scenario['emission']->fresh()->latestPuCurveVersion()->first()?->id)
        ->toBe($scenario['operational']->id)
        ->and(Activity::query()->where('description', 'pu_curve_promoted_operational')->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Autorização do executor
// ---------------------------------------------------------------------------

it('requires an explicit, existing, active, approved and authorized executor', function () {
    $scenario = approvedPromotionScenario();
    $unknownId = (string) (User::query()->max('id') + 9999);
    $inactive = PuCandidateGovernanceFixture::promotionExecutor();
    $inactive->forceFill(['is_active' => false])->save();
    $unapproved = PuCandidateGovernanceFixture::promotionExecutor();
    $unapproved->forceFill(['approved_at' => null])->save();
    $unauthorized = PuCandidateGovernanceFixture::actor([]);

    $missing = operationalPromotions()->write($scenario['promotion']->fresh(), null);
    $notFound = operationalPromotions()->write($scenario['promotion']->fresh(), $unknownId);
    $inactiveResult = operationalPromotions()->write($scenario['promotion']->fresh(), (string) $inactive->id);
    $unapprovedResult = operationalPromotions()->write($scenario['promotion']->fresh(), (string) $unapproved->id);
    $unauthorizedResult = operationalPromotions()->write($scenario['promotion']->fresh(), (string) $unauthorized->id);

    expect($missing->action)->toBe(PuCurveOperationalPromotionService::ACTION_EXECUTOR_REQUIRED)
        ->and($notFound->action)->toBe(PuCurveOperationalPromotionService::ACTION_EXECUTOR_NOT_FOUND)
        ->and($inactiveResult->action)->toBe(PuCurveOperationalPromotionService::ACTION_EXECUTOR_INACTIVE)
        ->and($unapprovedResult->action)->toBe(PuCurveOperationalPromotionService::ACTION_EXECUTOR_UNAPPROVED)
        ->and($unauthorizedResult->action)->toBe(PuCurveOperationalPromotionService::ACTION_EXECUTOR_UNAUTHORIZED)
        ->and($scenario['candidate']->fresh()->curve_role)->toBe(PuCurveRole::Candidate)
        ->and($scenario['promotion']->fresh()->status)->toBe(PuCurvePromotionStatus::Approved);
});

it('lets the requester execute an independently approved promotion', function () {
    $scenario = approvedPromotionScenario();

    $result = operationalPromotions()->write($scenario['promotion'], (string) $scenario['requester']->id);

    expect($result->action)->toBe(PuCurveOperationalPromotionService::ACTION_PROMOTED)
        ->and($scenario['promotion']->fresh()->executed_by)->toBe($scenario['requester']->id)
        ->and($scenario['promotion']->fresh()->reviewed_by)->toBe($scenario['reviewer']->id);
});

// ---------------------------------------------------------------------------
// Auditoria da execução
// ---------------------------------------------------------------------------

it('audits the operational switch exactly once', function () {
    $scenario = approvedPromotionScenario();
    operationalPromotions()->write($scenario['promotion'], (string) $scenario['executor']->id);
    operationalPromotions()->write($scenario['promotion']->fresh(), (string) $scenario['executor']->id);

    $activities = Activity::query()
        ->where('description', 'pu_curve_promoted_operational')
        ->where('subject_id', $scenario['emission']->id)
        ->get();
    $properties = $activities->first()->properties;

    expect($activities)->toHaveCount(1)
        ->and($properties['promotion_id'])->toBe($scenario['promotion']->id)
        ->and($properties['previous_operational_version_id'])->toBe($scenario['operational']->id)
        ->and($properties['new_operational_version_id'])->toBe($scenario['candidate']->id)
        ->and($properties['candidate_checksum'])->toBe($scenario['candidate']->curve_checksum)
        ->and($properties['external_validation_id'])->toBe($scenario['validation']->id)
        ->and($properties['comparison_sha256'])->toBe($scenario['validation']->comparison_sha256)
        ->and($properties['executor_id'])->toBe($scenario['executor']->id)
        ->and($properties['promoted_at'])->not->toBeNull()
        ->and($properties)->not->toHaveKeys(['rows', 'benchmark_rows', 'storage_path', 'temp_path']);
});

// ---------------------------------------------------------------------------
// Comando de execução
// ---------------------------------------------------------------------------

it('keeps the execute command read-only by default', function () {
    $scenario = approvedPromotionScenario();

    $this->artisan('pu:curve-promotion:execute', ['promotion' => $scenario['promotion']->id])
        ->expectsOutputToContain('Action: ready_to_execute')
        ->expectsOutputToContain('Writes: 0')
        ->assertSuccessful();

    expect($scenario['candidate']->fresh()->curve_role)->toBe(PuCurveRole::Candidate);
});

it('requires an explicit actor when the execute command is asked to write', function () {
    $scenario = approvedPromotionScenario();

    $this->artisan('pu:curve-promotion:execute', [
        'promotion' => $scenario['promotion']->id,
        '--write' => true,
    ])
        ->expectsOutputToContain('Action: promotion_executor_required')
        ->assertFailed();

    expect($scenario['candidate']->fresh()->curve_role)->toBe(PuCurveRole::Candidate);
});

it('refuses to execute an unapproved promotion through the command', function () {
    $scenario = approvedPromotionScenario(approve: false);

    $this->artisan('pu:curve-promotion:execute', [
        'promotion' => $scenario['promotion']->id,
        '--actor' => (string) $scenario['executor']->id,
        '--write' => true,
    ])
        ->expectsOutputToContain('Action: promotion_not_approved')
        ->assertFailed();

    expect($scenario['candidate']->fresh()->curve_role)->toBe(PuCurveRole::Candidate);
});

it('lets --dry-run win over --write on the execute command', function () {
    $scenario = approvedPromotionScenario();

    $this->artisan('pu:curve-promotion:execute', [
        'promotion' => $scenario['promotion']->id,
        '--actor' => (string) $scenario['executor']->id,
        '--dry-run' => true,
        '--write' => true,
    ])
        ->expectsOutputToContain('Writes: 0')
        ->assertSuccessful();

    expect($scenario['candidate']->fresh()->curve_role)->toBe(PuCurveRole::Candidate);
});

it('executes the atomic switch through the command', function () {
    $scenario = approvedPromotionScenario();

    $this->artisan('pu:curve-promotion:execute', [
        'promotion' => $scenario['promotion']->id,
        '--actor' => (string) $scenario['executor']->id,
        '--write' => true,
    ])
        ->expectsOutputToContain('Action: promotion_executed')
        ->expectsOutputToContain('Curve role: operational')
        ->assertSuccessful();

    expect($scenario['candidate']->fresh()->curve_role)->toBe(PuCurveRole::Operational)
        ->and($scenario['operational']->fresh()->status)->toBe(PuCurveStatus::Obsolete)
        ->and($scenario['emission']->fresh()->latestPuCurveVersion()->first()?->id)
        ->toBe($scenario['candidate']->id);
});
