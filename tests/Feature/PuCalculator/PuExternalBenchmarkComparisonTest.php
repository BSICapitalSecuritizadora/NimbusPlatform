<?php

use App\Domain\PuCalculator\DTOs\PuCandidateCurve;
use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Domain\PuCalculator\Enums\PuCurveExternalValidationStatus;
use App\Domain\PuCalculator\Enums\PuCurveReviewStatus;
use App\Domain\PuCalculator\Enums\PuCurveRole;
use App\Domain\PuCalculator\Enums\PuExternalValidationCoverageStatus;
use App\Domain\PuCalculator\Enums\PuExternalValidationGapType;
use App\Domain\PuCalculator\Services\PuCandidateExternalValidationEligibilityService;
use App\Domain\PuCalculator\Services\PuExternalBenchmarkComparisonPlanService;
use App\Domain\PuCalculator\Services\PuExternalBenchmarkComparisonService;
use App\Domain\PuCalculator\Services\PuExternalComparisonIntegrityService;
use App\Domain\PuCalculator\Services\PuExternalValidationActorService;
use App\Domain\PuCalculator\Services\PuNumericHomologationFinancialDiffService;
use App\Models\EmissionPuExternalValidation;
use App\Models\EmissionPuExternalValidationGap;
use App\Models\EmissionPuExternalValidationRow;
use Carbon\CarbonImmutable;
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
function comparisonCandidateValues(): array
{
    return [
        '2026-01-02' => '1000.0000000000000000',
        '2026-01-05' => '1010.0000000000000000',
        '2026-01-06' => '1020.0000000000000000',
    ];
}

function comparisonPlan(): PuExternalBenchmarkComparisonPlanService
{
    return app(PuExternalBenchmarkComparisonPlanService::class);
}

function comparisonWriter(): PuExternalBenchmarkComparisonService
{
    return app(PuExternalBenchmarkComparisonService::class);
}

// ---------------------------------------------------------------------------
// Boundary compartilhada com a 2B.5.15
// ---------------------------------------------------------------------------

it('keeps the 2B.5.15 in-memory comparison semantics unchanged', function () {
    $diff = app(PuNumericHomologationFinancialDiffService::class);
    $row = new PuDailyCurveRowData(
        date: CarbonImmutable::parse('2026-01-02'),
        isBusinessDay: true,
        unitBaseValue: '1000.0000000000000000',
        unitCorrectedValue: '1000.0000000000000000',
        factorDi: '1.0000000000000000',
        factorDiAccumulated: '1.0000000000000000',
        factorSpread: '1.0000000000000000',
        factorSpreadDi: '1.0000000000000000',
        interestRealUnitValue: '0.0000000000000000',
        updatedUnitValue: '1000.0000000000000000',
        amortizationRatio: '0.0000000000000000',
        amortizationUnitValue: '0.0000000000000000',
        amortizationValue: '0.0000000000000000',
        residualUnitValue: '1000.0000000000000000',
        quantity: '1000.0000',
        totalValue: '1000000.0000000000000000',
        interestPaymentUnitValue: '0.0000000000000000',
        interestPaymentValue: '0.0000000000000000',
        paymentTotalUnitValue: '0.0000000000000000',
        paymentTotalValue: '0.0000000000000000',
        dupCorrection: 0,
        dutCorrection: 0,
        dupInterest: 0,
        dutInterest: 0,
        indexRateDate: CarbonImmutable::parse('2026-01-02'),
        indexRateValue: '13.65000000',
        eventOriginalDate: null,
        eventEffectiveDate: null,
        calculationMemory: [],
    );
    $reference = [
        'source' => 'planilha de referência',
        'rows' => [['curve_date' => '2026-01-02', 'unit_value' => '1000.5000000000000000']],
    ];

    $legacy = $diff->compare(
        new PuCandidateCurve(
            rows: [$row],
            checksum: hash('sha256', 'legacy'),
            rowCount: 1,
            from: '2026-01-02',
            to: '2026-01-02',
            initialUnitValue: '1000.0000000000000000',
            lastUnitValue: '1000.0000000000000000',
            checkpoints: [],
        ),
        $reference,
    );
    $shared = $diff->compareRows(
        [['curve_date' => '2026-01-02', 'unit_value' => '1000.0000000000000000']],
        $reference,
    );

    expect($legacy->status)->toBe('compared')
        ->and($legacy->toArray())->toBe($shared->toArray())
        ->and($legacy->differences[0]['absolute_difference'])->toBe('0.5000000000000000')
        ->and($legacy->tolerancePolicy)->toBeNull();
});

it('reports the unavailable status unchanged when there is no reference', function () {
    $diff = app(PuNumericHomologationFinancialDiffService::class);

    $result = $diff->compareRows([['curve_date' => '2026-01-02', 'unit_value' => '1000']], null);

    expect($result->status)->toBe('unavailable')
        ->and($result->differences)->toBe([])
        ->and($result->tolerancePolicy)->toBeNull();
});

// ---------------------------------------------------------------------------
// Exact-date, sem fallback e sem interpolação
// ---------------------------------------------------------------------------

it('compares only exact matching dates', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);
    $benchmark = PuCandidateGovernanceFixture::persistedExternalBenchmark($emission, comparisonCandidateValues());

    $plan = comparisonPlan()->plan($candidate, $benchmark);

    expect($plan->action)->toBe(PuExternalBenchmarkComparisonPlanService::ACTION_READY)
        ->and($plan->coverageStatus)->toBe(PuExternalValidationCoverageStatus::Full->value)
        ->and($plan->comparedRows)->toBe(3)
        ->and($plan->gaps)->toBe([])
        ->and(array_column($plan->differences, 'reference_date'))
        ->toBe(['2026-01-02', '2026-01-05', '2026-01-06']);
});

it('never falls back to a neighbouring date when the benchmark misses the exact day', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission, unitValuesByDate: [
        '2026-01-05' => '1010.0000000000000000',
    ]);
    $benchmark = PuCandidateGovernanceFixture::persistedExternalBenchmark($emission, [
        '2026-01-02' => '1000.0000000000000000',
        '2026-01-06' => '1020.0000000000000000',
    ]);

    $plan = comparisonPlan()->plan($candidate, $benchmark);

    expect($plan->action)->toBe(PuExternalBenchmarkComparisonPlanService::ACTION_NO_OVERLAP)
        ->and($plan->coverageStatus)->toBe(PuExternalValidationCoverageStatus::None->value)
        ->and($plan->comparedRows)->toBe(0)
        ->and($plan->differences)->toBe([])
        ->and($plan->writes)->toBe(0);
});

it('refuses to persist a dossier when there is no exact date overlap', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission, unitValuesByDate: [
        '2026-01-05' => '1010.0000000000000000',
    ]);
    $benchmark = PuCandidateGovernanceFixture::persistedExternalBenchmark($emission, [
        '2026-01-06' => '1020.0000000000000000',
    ]);
    $before = PuCandidateGovernanceFixture::counts();

    $result = comparisonWriter()->write(
        $candidate,
        $benchmark,
        (string) PuCandidateGovernanceFixture::checker()->id,
    );

    expect($result->action)->toBe(PuExternalBenchmarkComparisonPlanService::ACTION_NO_OVERLAP)
        ->and($result->writes)->toBe(0)
        ->and(PuCandidateGovernanceFixture::counts())->toBe($before);
});

// ---------------------------------------------------------------------------
// Diferenças financeiras
// ---------------------------------------------------------------------------

it('computes exact absolute and relative differences', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission, unitValuesByDate: [
        '2026-01-02' => '1000.0000000000000000',
    ]);
    $benchmark = PuCandidateGovernanceFixture::persistedExternalBenchmark($emission, [
        '2026-01-02' => '800.0000000000000000',
    ]);

    $plan = comparisonPlan()->plan($candidate, $benchmark);

    expect($plan->differences[0]['candidate_unit_value'])->toBe('1000.0000000000000000')
        ->and($plan->differences[0]['external_unit_value'])->toBe('800.0000000000000000')
        ->and($plan->differences[0]['absolute_difference'])->toBe('200.0000000000000000')
        ->and($plan->differences[0]['relative_difference_percentage'])->toBe('25.0000000000000000')
        ->and($plan->maximumAbsoluteDifference)->toBe('200.0000000000000000')
        ->and($plan->maximumRelativeDifference)->toBe('25.0000000000000000');
});

it('reports a null relative difference when the external reference is zero', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission, unitValuesByDate: [
        '2026-01-02' => '1000.0000000000000000',
    ]);
    $benchmark = PuCandidateGovernanceFixture::persistedExternalBenchmark($emission, [
        '2026-01-02' => '0',
    ]);

    $plan = comparisonPlan()->plan($candidate, $benchmark);

    expect($plan->differences[0]['external_unit_value'])->toBe('0.0000000000000000')
        ->and($plan->differences[0]['absolute_difference'])->toBe('1000.0000000000000000')
        ->and($plan->differences[0]['relative_difference_percentage'])->toBeNull();
});

it('never classifies a difference against a tolerance policy', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission, unitValuesByDate: [
        '2026-01-02' => '1000.0000000000000000',
    ]);
    $benchmark = PuCandidateGovernanceFixture::persistedExternalBenchmark($emission, [
        '2026-01-02' => '1.0000000000000000',
    ]);

    $plan = comparisonPlan()->plan($candidate, $benchmark);

    expect($plan->tolerancePolicy)->toBeNull()
        ->and($plan->differences[0]['classification'])->toBe('reported_without_tolerance');
});

// ---------------------------------------------------------------------------
// Cobertura e gaps
// ---------------------------------------------------------------------------

it('records partial coverage with gaps on both sides and no invented rows', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission, unitValuesByDate: [
        '2026-01-02' => '1000.0000000000000000',
        '2026-01-05' => '1010.0000000000000000',
    ]);
    $benchmark = PuCandidateGovernanceFixture::persistedExternalBenchmark($emission, [
        '2026-01-02' => '1000.0000000000000000',
        '2026-01-06' => '1020.0000000000000000',
    ]);

    $result = comparisonWriter()->write(
        $candidate,
        $benchmark,
        (string) PuCandidateGovernanceFixture::checker()->id,
    );
    $validation = EmissionPuExternalValidation::query()->findOrFail($result->externalValidationId);

    expect($result->action)->toBe(PuExternalBenchmarkComparisonService::ACTION_CREATED)
        ->and($validation->coverage_status)->toBe(PuExternalValidationCoverageStatus::Partial)
        ->and($validation->compared_rows)->toBe(1)
        ->and($validation->candidate_dates_without_reference)->toBe(1)
        ->and($validation->reference_dates_without_candidate)->toBe(1)
        ->and($validation->rows()->get()->map(
            fn (EmissionPuExternalValidationRow $row): string => $row->reference_date->toDateString(),
        )->all())->toBe(['2026-01-02'])
        ->and($validation->gaps()->orderBy('reference_date')->get()
            ->map(fn (EmissionPuExternalValidationGap $gap): array => [
                $gap->reference_date->toDateString(),
                $gap->gap_type->value,
            ])->all())
        ->toBe([
            ['2026-01-05', PuExternalValidationGapType::CandidateWithoutReference->value],
            ['2026-01-06', PuExternalValidationGapType::ReferenceWithoutCandidate->value],
        ]);
});

it('never turns a coverage gap into a financial difference row', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission, unitValuesByDate: [
        '2026-01-02' => '1000.0000000000000000',
        '2026-01-05' => '1010.0000000000000000',
    ]);
    $benchmark = PuCandidateGovernanceFixture::persistedExternalBenchmark($emission, [
        '2026-01-02' => '1000.0000000000000000',
    ]);

    $result = comparisonWriter()->write(
        $candidate,
        $benchmark,
        (string) PuCandidateGovernanceFixture::checker()->id,
    );

    expect(EmissionPuExternalValidationRow::query()->count())->toBe(1)
        ->and(EmissionPuExternalValidationGap::query()->count())->toBe(1)
        ->and(EmissionPuExternalValidationRow::query()
            ->where('external_validation_id', $result->externalValidationId)
            ->whereDate('reference_date', '2026-01-05')
            ->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Governança do write
// ---------------------------------------------------------------------------

it('requires an explicit authorized actor to persist a comparison', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);
    $benchmark = PuCandidateGovernanceFixture::persistedExternalBenchmark($emission, comparisonCandidateValues());
    $before = PuCandidateGovernanceFixture::counts();

    $result = comparisonWriter()->write($candidate, $benchmark, null);

    expect($result->action)->toBe(PuExternalValidationActorService::ACTOR_REQUIRED)
        ->and($result->writes)->toBe(0)
        ->and(PuCandidateGovernanceFixture::counts())->toBe($before);
});

it('refuses to compare a candidate and a benchmark from different emissions', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);
    $foreign = PuCandidateGovernanceFixture::persistedExternalBenchmark(
        PuCandidateGovernanceFixture::emission(),
        comparisonCandidateValues(),
    );

    $result = comparisonWriter()->write(
        $candidate,
        $foreign,
        (string) PuCandidateGovernanceFixture::checker()->id,
    );

    expect($result->action)->toBe(PuExternalBenchmarkComparisonPlanService::ACTION_BENCHMARK_MISMATCH)
        ->and($result->writes)->toBe(0);
});

it('refuses to compare against a missing benchmark', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);

    $result = comparisonPlan()->plan($candidate, null);

    expect($result->action)->toBe(PuExternalBenchmarkComparisonPlanService::ACTION_BENCHMARK_NOT_FOUND)
        ->and($result->writes)->toBe(0);
});

it('refuses to compare a candidate that is not internally approved', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);
    $benchmark = PuCandidateGovernanceFixture::persistedExternalBenchmark($emission, comparisonCandidateValues());
    DB::table('emission_pu_curve_versions')
        ->where('id', $candidate->id)
        ->update(['review_status' => PuCurveReviewStatus::PendingReview->value]);

    $result = comparisonWriter()->write(
        $candidate->fresh(),
        $benchmark,
        (string) PuCandidateGovernanceFixture::checker()->id,
    );

    expect($result->action)->toBe(PuCandidateExternalValidationEligibilityService::ACTION_NOT_READY)
        ->and($result->writes)->toBe(0);
});

it('blocks a comparison when the benchmark dataset checksum no longer matches', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);
    $benchmark = PuCandidateGovernanceFixture::persistedExternalBenchmark($emission, comparisonCandidateValues());
    DB::table('emission_pu_external_benchmark_rows')
        ->where('id', $benchmark->rows()->orderBy('id')->value('id'))
        ->update(['unit_value' => '1.0000000000000000']);
    $before = PuCandidateGovernanceFixture::counts();

    $result = comparisonWriter()->write(
        $candidate,
        $benchmark->fresh(),
        (string) PuCandidateGovernanceFixture::checker()->id,
    );

    expect($result->action)->toBe(PuExternalBenchmarkComparisonPlanService::ACTION_BENCHMARK_INTEGRITY_FAILURE)
        ->and($result->writes)->toBe(0)
        ->and(PuCandidateGovernanceFixture::counts())->toBe($before);
});

it('blocks a comparison when the candidate checksum no longer matches its rows', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);
    $benchmark = PuCandidateGovernanceFixture::persistedExternalBenchmark($emission, comparisonCandidateValues());
    DB::table('emission_pu_curve_versions')
        ->where('id', $candidate->id)
        ->update(['curve_checksum' => hash('sha256', 'corrupted')]);
    $before = PuCandidateGovernanceFixture::counts();

    $result = comparisonWriter()->write(
        $candidate->fresh(),
        $benchmark,
        (string) PuCandidateGovernanceFixture::checker()->id,
    );

    expect($result->action)->toBe(PuCandidateExternalValidationEligibilityService::ACTION_INTEGRITY_FAILURE)
        ->and($result->writes)->toBe(0)
        ->and(PuCandidateGovernanceFixture::counts())->toBe($before);
});

// ---------------------------------------------------------------------------
// Reprodutibilidade, idempotência e append-only
// ---------------------------------------------------------------------------

it('is deterministic for the same candidate and benchmark', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);
    $benchmark = PuCandidateGovernanceFixture::persistedExternalBenchmark($emission, comparisonCandidateValues());

    $first = comparisonPlan()->plan($candidate, $benchmark);
    $second = comparisonPlan()->plan($candidate->fresh(), $benchmark->fresh());

    expect($second->comparisonSha256)->toBe($first->comparisonSha256)
        ->and($second->differences)->toBe($first->differences)
        ->and($second->gaps)->toBe($first->gaps)
        ->and($second->coverageStatus)->toBe($first->coverageStatus)
        ->and($first->comparisonAlgorithmVersion)->toBe('exact-date-financial-diff-v1');
});

it('is idempotent and never duplicates comparison rows or gaps', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);
    $benchmark = PuCandidateGovernanceFixture::persistedExternalBenchmark($emission, comparisonCandidateValues());
    $actor = PuCandidateGovernanceFixture::checker();

    $first = comparisonWriter()->write($candidate, $benchmark, (string) $actor->id);
    $afterFirst = PuCandidateGovernanceFixture::counts();
    $auditAfterFirst = Activity::query()->where('description', 'pu_external_comparison_created')->count();

    $second = comparisonWriter()->write($candidate->fresh(), $benchmark->fresh(), (string) $actor->id);

    expect($first->action)->toBe(PuExternalBenchmarkComparisonService::ACTION_CREATED)
        ->and($second->action)->toBe(PuExternalBenchmarkComparisonPlanService::ACTION_ALREADY_COMPARED)
        ->and($second->writes)->toBe(0)
        ->and($second->externalValidationId)->toBe($first->externalValidationId)
        ->and(PuCandidateGovernanceFixture::counts())->toBe($afterFirst)
        ->and(Activity::query()->where('description', 'pu_external_comparison_created')->count())
        ->toBe($auditAfterFirst);
});

it('appends a separate dossier for a different benchmark', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);
    $actor = PuCandidateGovernanceFixture::checker();
    $first = PuCandidateGovernanceFixture::persistedExternalBenchmark($emission, comparisonCandidateValues());
    $second = PuCandidateGovernanceFixture::persistedExternalBenchmark($emission, [
        '2026-01-02' => '1001.0000000000000000',
        '2026-01-05' => '1011.0000000000000000',
        '2026-01-06' => '1021.0000000000000000',
    ], sourceName: 'Segundo agente');

    $firstResult = comparisonWriter()->write($candidate, $first, (string) $actor->id);
    $secondResult = comparisonWriter()->write($candidate->fresh(), $second, (string) $actor->id);

    expect($secondResult->externalValidationId)->not->toBe($firstResult->externalValidationId)
        ->and(EmissionPuExternalValidation::query()->count())->toBe(2)
        ->and($secondResult->comparisonSha256)->not->toBe($firstResult->comparisonSha256);
});

it('keeps a persisted comparison row and gap immutable', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission, unitValuesByDate: [
        '2026-01-02' => '1000.0000000000000000',
        '2026-01-05' => '1010.0000000000000000',
    ]);
    $benchmark = PuCandidateGovernanceFixture::persistedExternalBenchmark($emission, [
        '2026-01-02' => '1000.0000000000000000',
    ]);
    $validation = PuCandidateGovernanceFixture::persistedExternalComparison($candidate, $benchmark);

    expect(fn () => $validation->rows()->firstOrFail()->update(['absolute_difference' => '9.0000000000000000']))
        ->toThrow(LogicException::class, 'immutable')
        ->and(fn () => $validation->gaps()->firstOrFail()->delete())
        ->toThrow(LogicException::class, 'immutable')
        ->and(fn () => $validation->update(['comparison_sha256' => hash('sha256', 'other')]))
        ->toThrow(LogicException::class, 'immutable')
        ->and(fn () => $validation->delete())
        ->toThrow(LogicException::class, 'cannot be deleted');
});

it('confirms and then detects corruption of the persisted comparison checksum', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);
    $benchmark = PuCandidateGovernanceFixture::persistedExternalBenchmark($emission, comparisonCandidateValues());
    $validation = PuCandidateGovernanceFixture::persistedExternalComparison($candidate, $benchmark);
    $integrity = app(PuExternalComparisonIntegrityService::class);

    expect($integrity->inspect($validation)['valid'])->toBeTrue();

    DB::table('emission_pu_external_validations')
        ->where('id', $validation->id)
        ->update(['comparison_sha256' => hash('sha256', 'corrupted')]);

    expect($integrity->inspect($validation->fresh())['valid'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// Zero decisão automática e zero efeito operacional
// ---------------------------------------------------------------------------

it('leaves the candidate external validation pending even with a zero difference', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);
    $benchmark = PuCandidateGovernanceFixture::persistedExternalBenchmark($emission, comparisonCandidateValues());

    $result = comparisonWriter()->write(
        $candidate,
        $benchmark,
        (string) PuCandidateGovernanceFixture::checker()->id,
    );
    $validation = EmissionPuExternalValidation::query()->findOrFail($result->externalValidationId);

    expect($result->maximumAbsoluteDifference)->toBe('0.0000000000000000')
        ->and($validation->status)->toBe(PuCurveExternalValidationStatus::Pending)
        ->and($validation->reviewed_by)->toBeNull()
        ->and($validation->reviewed_at)->toBeNull()
        ->and($candidate->fresh()->external_validation_status)->toBe(PuCurveExternalValidationStatus::Pending)
        ->and($candidate->fresh()->curve_role)->toBe(PuCurveRole::Candidate);
});

it('creates a comparison with zero financial and zero operational side effects', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $operational = PuCandidateGovernanceFixture::operationalCurve($emission);
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);
    $benchmark = PuCandidateGovernanceFixture::persistedExternalBenchmark($emission, comparisonCandidateValues());
    $before = PuCandidateGovernanceFixture::counts();
    $operationalBefore = $operational->fresh()->only(['curve_role', 'status', 'rows_count', 'updated_at']);

    comparisonWriter()->write($candidate, $benchmark, (string) PuCandidateGovernanceFixture::checker()->id);
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
        ->and($after['external_benchmarks'])->toBe($before['external_benchmarks'])
        ->and($after['external_validations'])->toBe($before['external_validations'] + 1)
        ->and($after['external_validation_rows'])->toBe($before['external_validation_rows'] + 3)
        ->and($operational->fresh()->only(['curve_role', 'status', 'rows_count', 'updated_at']))
        ->toEqual($operationalBefore);
});

// ---------------------------------------------------------------------------
// Auditoria e comando
// ---------------------------------------------------------------------------

it('audits the comparison with the three checksums and the coverage', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);
    $benchmark = PuCandidateGovernanceFixture::persistedExternalBenchmark($emission, comparisonCandidateValues());
    $actor = PuCandidateGovernanceFixture::checker();

    $result = comparisonWriter()->write($candidate, $benchmark, (string) $actor->id);
    $properties = Activity::query()
        ->where('description', 'pu_external_comparison_created')
        ->latest('id')
        ->firstOrFail()
        ->properties
        ->all();

    expect($properties['candidate_checksum'])->toBe($candidate->curve_checksum)
        ->and($properties['benchmark_dataset_sha256'])->toBe($benchmark->dataset_sha256)
        ->and($properties['comparison_sha256'])->toBe($result->comparisonSha256)
        ->and($properties['coverage_status'])->toBe(PuExternalValidationCoverageStatus::Full->value)
        ->and($properties['compared_rows'])->toBe(3)
        ->and($properties['actor_id'])->toBe($actor->id);
});

it('runs the compare command read-only by default and writes only with --write', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);
    $benchmark = PuCandidateGovernanceFixture::persistedExternalBenchmark($emission, comparisonCandidateValues());

    $this->artisan('pu:curve-candidate:compare-external-benchmark', [
        'version' => $candidate->id,
        'benchmark' => $benchmark->id,
    ])
        ->expectsOutputToContain('Action: '.PuExternalBenchmarkComparisonPlanService::ACTION_READY)
        ->expectsOutputToContain('Writes: 0')
        ->expectsOutputToContain('Tolerance policy: null (reported_without_tolerance)')
        ->assertSuccessful();

    expect(EmissionPuExternalValidation::query()->count())->toBe(0);

    $this->artisan('pu:curve-candidate:compare-external-benchmark', [
        'version' => $candidate->id,
        'benchmark' => $benchmark->id,
        '--actor' => (string) PuCandidateGovernanceFixture::checker()->id,
        '--write' => true,
    ])
        ->expectsOutputToContain('Action: '.PuExternalBenchmarkComparisonService::ACTION_CREATED)
        ->expectsOutputToContain('Writes: 1')
        ->assertSuccessful();

    expect(EmissionPuExternalValidation::query()->count())->toBe(1);
});

it('lets dry-run win over write in the compare command', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);
    $benchmark = PuCandidateGovernanceFixture::persistedExternalBenchmark($emission, comparisonCandidateValues());

    $this->artisan('pu:curve-candidate:compare-external-benchmark', [
        'version' => $candidate->id,
        'benchmark' => $benchmark->id,
        '--actor' => (string) PuCandidateGovernanceFixture::checker()->id,
        '--dry-run' => true,
        '--write' => true,
    ])
        ->expectsOutputToContain('Writes: 0')
        ->assertSuccessful();

    expect(EmissionPuExternalValidation::query()->count())->toBe(0);
});

it('rejects a non numeric benchmark argument in the compare command', function () {
    $this->artisan('pu:curve-candidate:compare-external-benchmark', [
        'version' => '1',
        'benchmark' => 'abc',
    ])->assertFailed();
});
