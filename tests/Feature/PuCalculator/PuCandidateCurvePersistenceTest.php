<?php

use App\Domain\PuCalculator\DTOs\PuCurveGenerationResult;
use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Domain\PuCalculator\DTOs\PuNumericHomologationPlan;
use App\Domain\PuCalculator\Enums\PuCurveExternalValidationStatus;
use App\Domain\PuCalculator\Enums\PuCurveInternalValidationStatus;
use App\Domain\PuCalculator\Enums\PuCurveReviewStatus;
use App\Domain\PuCalculator\Enums\PuCurveRole;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Services\PuCandidateCurvePersistencePlanService;
use App\Domain\PuCalculator\Services\PuCandidateCurvePersistenceService;
use App\Domain\PuCalculator\Services\PuCurveExportService;
use App\Domain\PuCalculator\Services\PuCurvePersistenceService;
use App\Domain\PuCalculator\Services\PuCurveVersionService;
use App\Domain\PuCalculator\Services\PuHomologationReportService;
use App\Domain\PuCalculator\Services\PuIpcaHomologationStatusService;
use App\Domain\PuCalculator\Services\PuNumericHomologationPlanService;
use App\Domain\PuCalculator\Services\PuNumericHomologationService;
use App\Domain\PuCalculator\Services\PuPersistedCurveChecksumService;
use App\Enums\AccessPermission;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\IndexRate;
use App\Models\Payment;
use App\Models\PuHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Pu\PuCandidateGovernanceFixture;

uses(RefreshDatabase::class);

/**
 * Dobre que simula TOCTOU real: entre o plano usado para calcular o candidate e a
 * revalidação dentro da transação, um insumo obrigatório desaparece do banco. O
 * fingerprint recalculado deixa de bater e a persistência precisa abortar.
 */
class PuCandidatePersistencePlanSpy extends PuNumericHomologationPlanService
{
    /** @var (callable():void)|null */
    public $mutateInsideTransaction = null;

    public function plan(Emission $emission, CarbonImmutable $asOf): PuNumericHomologationPlan
    {
        // `PuNumericHomologationService::evaluate()` planeja duas vezes fora da
        // transação; só a revalidação da persistência roda dentro dela.
        // RefreshDatabase já mantém o teste dentro de uma transação (nível 1),
        // então a revalidação aninhada do `write` roda no nível 2.
        if ($this->mutateInsideTransaction !== null && DB::transactionLevel() > 1) {
            ($this->mutateInsideTransaction)();
            $this->mutateInsideTransaction = null;
        }

        return parent::plan($emission, $asOf);
    }
}

/**
 * Dobre que corrompe apenas a leitura do checksum persistido, para provar que o
 * guard de integridade reverte a transação inteira.
 */
class PuCandidateCorruptChecksumSpy extends PuPersistedCurveChecksumService
{
    public function checksum(EmissionPuCurveVersion $version): string
    {
        return str_repeat('f', 64);
    }
}

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

function candidatePersistenceEmission(): Emission
{
    return Emission::factory()->create([
        'type' => 'CRI',
        'status' => 'active',
        'issued_quantity' => 1000,
    ]);
}

/**
 * Versão operacional com as linhas fortemente vinculadas a ela.
 */
function candidatePersistenceOperationalVersion(
    Emission $emission,
    string $calculationVersion,
    string $curveDate,
    PuCurveStatus $status = PuCurveStatus::Generated,
): EmissionPuCurveVersion {
    $version = EmissionPuCurveVersion::factory()->create([
        'emission_id' => $emission->id,
        'calculation_version' => $calculationVersion,
        'status' => $status->value,
        'rows_count' => 1,
    ]);

    EmissionPuDailyCurve::factory()->create([
        'emission_id' => $emission->id,
        'curve_version_id' => $version->id,
        'calculation_version' => $calculationVersion,
        'curve_date' => $curveDate,
    ]);

    return $version->fresh();
}

/**
 * Candidate persistida com as linhas fortemente vinculadas a ela.
 */
function candidatePersistenceCandidateVersion(
    Emission $emission,
    string $calculationVersion,
    string $curveDate,
    array $overrides = [],
): EmissionPuCurveVersion {
    $version = EmissionPuCurveVersion::factory()->candidate()->create([
        'emission_id' => $emission->id,
        'calculation_version' => $calculationVersion,
        'rows_count' => 1,
        ...$overrides,
    ]);

    EmissionPuDailyCurve::factory()->create([
        'emission_id' => $emission->id,
        'curve_version_id' => $version->id,
        'calculation_version' => $calculationVersion,
        'curve_date' => $curveDate,
    ]);

    return $version->fresh();
}

/**
 * Linha mínima porém completa para exercitar o writer operacional sem a engine.
 */
function candidatePersistenceRow(string $date): PuDailyCurveRowData
{
    return new PuDailyCurveRowData(
        date: CarbonImmutable::parse($date),
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
        indexRateDate: null,
        indexRateValue: null,
        eventOriginalDate: null,
        eventEffectiveDate: null,
        calculationMemory: ['engine_version' => 'test'],
    );
}

/**
 * Muda o valor da última taxa publicada sem `UPDATE ... LIMIT` (não portável no
 * SQLite): resolve a chave primária primeiro e grava por `whereKey`.
 */
function candidatePersistenceChangeLastRate(string $value): void
{
    $rateId = IndexRate::query()->orderByDesc('rate_date')->orderByDesc('id')->value('id');

    IndexRate::query()->whereKey($rateId)->update(['rate_value' => $value]);
}

/**
 * Usuário capaz de atravessar o grupo ['auth', 'approved', EnsureTwoFactorEnabled].
 *
 * @param  list<string>  $permissions
 */
function candidatePersistenceWebUser(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission);
    }

    $user = User::factory()->withTwoFactor()->create(['approved_at' => now()]);
    $user->givePermissionTo($permissions);

    return $user->fresh();
}

it('keeps the operational curve as the latest one even when a newer candidate exists', function () {
    $emission = candidatePersistenceEmission();
    $operational = candidatePersistenceOperationalVersion($emission, 'v1', '2026-01-02');
    $candidate = candidatePersistenceCandidateVersion($emission, 'v2', '2026-01-05');
    $emission = $emission->fresh();

    expect($candidate->id)->toBeGreaterThan($operational->id)
        ->and(EmissionPuDailyCurve::latestCalculationVersionForEmission($emission->id))->toBe('v1')
        ->and($emission->latestPuCurveVersion()->first()?->id)->toBe($operational->id)
        ->and($emission->currentPuCurveVersion()?->id)->toBe($operational->id)
        ->and($emission->latestCandidatePuCurveVersion()->first()?->id)->toBe($candidate->id)
        ->and($emission->operationalPuDailyCurves()->pluck('calculation_version')->all())->toBe(['v1'])
        ->and($emission->operationalPuCurveVersions()->pluck('id')->all())->toBe([$operational->id])
        ->and($emission->candidatePuCurveVersions()->pluck('id')->all())->toBe([$candidate->id])
        ->and($emission->puCurveVersions()->count())->toBe(2);
});

it('treats legacy rows without a version link as operational', function () {
    $emission = candidatePersistenceEmission();
    EmissionPuDailyCurve::factory()->create([
        'emission_id' => $emission->id,
        'curve_version_id' => null,
        'calculation_version' => 'v9',
        'curve_date' => '2026-01-02',
    ]);
    candidatePersistenceCandidateVersion($emission, 'v10', '2026-01-05');
    $emission = $emission->fresh();

    expect(EmissionPuDailyCurve::latestCalculationVersionForEmission($emission->id))->toBe('v9')
        ->and($emission->operationalPuDailyCurves()->count())->toBe(1)
        ->and($emission->operationalPuDailyCurves()->first()?->curve_version_id)->toBeNull()
        ->and(EmissionPuDailyCurve::query()->candidate()->count())->toBe(1);
});

it('hides candidate versions from every implicit operational selector', function () {
    $emission = candidatePersistenceEmission();
    $operational = candidatePersistenceOperationalVersion($emission, 'v1', '2026-01-02');
    candidatePersistenceCandidateVersion($emission, 'v2', '2026-01-05', [
        'status' => PuCurveStatus::Homologated->value,
    ]);
    $emission = $emission->fresh();
    $versions = app(PuCurveVersionService::class);

    expect($versions->findByCalculationVersion($emission, 'v2'))->toBeNull()
        ->and($versions->findByCalculationVersion($emission, 'v1')?->id)->toBe($operational->id)
        ->and($versions->hasHomologatedVersion($emission))->toBeFalse()
        ->and(app(PuIpcaHomologationStatusService::class)->isOperationallyHomologated($emission))->toBeFalse()
        ->and(app(PuCurveExportService::class)->rows($emission)->pluck('calculation_version')->all())->toBe(['v1'])
        ->and(app(PuCurveExportService::class)->rows($emission, 'v2')->count())->toBe(0);
});

it('never marks the operational version obsolete when a candidate is generated', function () {
    $emission = candidatePersistenceEmission();
    $operational = candidatePersistenceOperationalVersion($emission, 'v1', '2026-01-02');
    $candidate = EmissionPuCurveVersion::factory()->candidate()->create([
        'emission_id' => $emission->id,
        'calculation_version' => 'v2',
        'status' => PuCurveStatus::Processing->value,
    ]);

    app(PuCurveVersionService::class)->markGenerated($candidate, 3, 'v2');

    expect($operational->fresh()->status)->toBe(PuCurveStatus::Generated)
        ->and($operational->fresh()->obsolete_reason)->toBeNull()
        ->and($candidate->fresh()->curve_role)->toBe(PuCurveRole::Candidate)
        ->and($emission->fresh()->latestPuCurveVersion()->first()?->id)->toBe($operational->id);
});

it('allocates calculation versions across every role so candidate and operational never collide', function () {
    $emission = candidatePersistenceEmission();
    candidatePersistenceOperationalVersion($emission, 'v10', '2026-01-02');
    candidatePersistenceCandidateVersion($emission, 'v11', '2026-01-05');

    expect(app(PuCurveVersionService::class)->nextCalculationVersion($emission->fresh()))->toBe('v12');
});

it('keeps the operational writer lifecycle and links every persisted row to its version', function () {
    $emission = candidatePersistenceEmission();
    $writer = app(PuCurvePersistenceService::class);

    $first = $writer->handle(
        $emission,
        new PuCurveGenerationResult([candidatePersistenceRow('2026-01-02')]),
        false,
    );
    $second = $writer->handle(
        $emission->fresh(),
        new PuCurveGenerationResult([candidatePersistenceRow('2026-01-05')]),
        false,
    );

    $versions = EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->orderBy('id')->get();

    expect($first->calculationVersion)->toBe('v1')
        ->and($second->calculationVersion)->toBe('v2')
        ->and($versions)->toHaveCount(2)
        ->and($versions[0]->curve_role)->toBe(PuCurveRole::Operational)
        ->and($versions[0]->review_status)->toBe(PuCurveReviewStatus::NotApplicable)
        ->and($versions[0]->status)->toBe(PuCurveStatus::Obsolete)
        ->and($versions[0]->obsolete_reason)->toBe('superseded')
        ->and($versions[1]->status)->toBe(PuCurveStatus::Generated)
        ->and($versions[1]->rows_count)->toBe(1)
        ->and(EmissionPuDailyCurve::query()->whereNull('curve_version_id')->count())->toBe(0)
        ->and($versions[0]->dailyCurves()->pluck('calculation_version')->all())->toBe(['v1'])
        ->and($versions[1]->dailyCurves()->pluck('calculation_version')->all())->toBe(['v2'])
        ->and(EmissionPuDailyCurve::latestCalculationVersionForEmission($emission->id))->toBe('v2');
});

it('refuses to mutate or delete a persisted candidate row', function () {
    $emission = candidatePersistenceEmission();
    $version = candidatePersistenceCandidateVersion($emission, 'v1', '2026-01-02');
    $row = $version->dailyCurves()->firstOrFail();

    expect(fn () => $row->update(['updated_unit_value' => '1.0000000000000000']))
        ->toThrow(LogicException::class)
        ->and(fn () => $row->delete())->toThrow(LogicException::class)
        ->and($row->fresh()->updated_unit_value)->toBe('1000.0000000000000000');
});

it('keeps operational rows fully mutable', function () {
    $emission = candidatePersistenceEmission();
    $version = candidatePersistenceOperationalVersion($emission, 'v1', '2026-01-02');
    $linked = $version->dailyCurves()->firstOrFail();
    $legacy = EmissionPuDailyCurve::factory()->create([
        'emission_id' => $emission->id,
        'curve_version_id' => null,
        'calculation_version' => 'v1',
        'curve_date' => '2026-01-06',
    ]);

    $linked->update(['dut_interest' => 7]);
    $legacy->update(['dut_interest' => 9]);

    expect($linked->fresh()->dut_interest)->toBe(7)
        ->and($legacy->fresh()->dut_interest)->toBe(9)
        ->and($legacy->delete())->toBeTrue();
});

it('freezes the candidate identity while leaving only the review fields writable', function () {
    $emission = candidatePersistenceEmission();
    $version = candidatePersistenceCandidateVersion($emission, 'v1', '2026-01-02');
    $reviewer = PuCandidateGovernanceFixture::checker();

    expect(fn () => $version->forceFill(['curve_checksum' => str_repeat('0', 64)])->save())
        ->toThrow(LogicException::class)
        ->and(fn () => $version->fresh()->forceFill(['curve_role' => PuCurveRole::Operational])->save())
        ->toThrow(LogicException::class)
        ->and(fn () => $version->fresh()->forceFill(['generated_by' => $reviewer->id])->save())
        ->toThrow(LogicException::class)
        ->and(fn () => $version->fresh()->forceFill(['rows_count' => 999])->save())
        ->toThrow(LogicException::class)
        ->and(fn () => $version->fresh()->delete())->toThrow(LogicException::class);

    $fresh = $version->fresh();
    $fresh->forceFill([
        'review_status' => PuCurveReviewStatus::Approved,
        'reviewed_by' => $reviewer->id,
        'reviewed_at' => now(),
        'review_reason' => null,
    ])->save();

    expect($fresh->fresh()->review_status)->toBe(PuCurveReviewStatus::Approved)
        ->and(EmissionPuCurveVersion::REVIEW_MUTABLE_FIELDS)->toContain('updated_at');
});

it('reports candidate persistence as not ready while the numeric homologation is blocked', function () {
    $emission = candidatePersistenceEmission();
    $before = PuCandidateGovernanceFixture::counts();

    $result = app(PuCandidateCurvePersistenceService::class)->dryRun(
        $emission,
        CarbonImmutable::parse(PuCandidateGovernanceFixture::AS_OF),
    );

    expect($result->action)->toBe(PuCandidateCurvePersistencePlanService::ACTION_NOT_READY)
        ->and($result->writes)->toBe(0)
        ->and($result->candidateVersionId)->toBeNull()
        ->and($result->plan->homologation->action)->toBe(PuNumericHomologationService::ACTION_NOT_READY)
        ->and($result->plan->homologation->candidate)->toBeNull()
        ->and(PuCandidateGovernanceFixture::counts())->toBe($before);
});

it('rejects every unusable maker before touching the database', function (
    string $case,
    string $expectedAction,
) {
    $emission = candidatePersistenceEmission();
    $identifier = match ($case) {
        'missing' => null,
        'unknown' => 'ghost@example.com',
        'inactive' => tap(PuCandidateGovernanceFixture::maker(), fn (User $actor) => $actor
            ->forceFill(['is_active' => false])->save())->email,
        'unapproved' => tap(PuCandidateGovernanceFixture::maker(), fn (User $actor) => $actor
            ->forceFill(['approved_at' => null])->save())->email,
        'unauthorized' => User::factory()->create()->email,
    };
    $before = PuCandidateGovernanceFixture::counts();

    $result = app(PuCandidateCurvePersistenceService::class)->write(
        $emission,
        CarbonImmutable::parse(PuCandidateGovernanceFixture::AS_OF),
        $identifier,
    );

    expect($result->action)->toBe($expectedAction)
        ->and($result->writes)->toBe(0)
        ->and(PuCandidateGovernanceFixture::counts())->toBe($before);
})->with([
    'no actor' => ['missing', PuCandidateCurvePersistenceService::ACTION_ACTOR_REQUIRED],
    'unknown actor' => ['unknown', PuCandidateCurvePersistenceService::ACTION_ACTOR_NOT_FOUND],
    'inactive actor' => ['inactive', PuCandidateCurvePersistenceService::ACTION_ACTOR_INACTIVE],
    'unapproved actor' => ['unapproved', PuCandidateCurvePersistenceService::ACTION_ACTOR_UNAPPROVED],
    'unauthorized actor' => ['unauthorized', PuCandidateCurvePersistenceService::ACTION_ACTOR_UNAUTHORIZED],
]);

it('persists the validated candidate as an isolated append-only artifact', function () {
    $asOf = PuCandidateGovernanceFixture::asOf();
    $emission = PuCandidateGovernanceFixture::readyEmission($asOf);
    $operational = candidatePersistenceOperationalVersion($emission, 'v1', '2026-05-15');
    $maker = PuCandidateGovernanceFixture::maker();
    $homologation = app(PuNumericHomologationService::class)->evaluate($emission->fresh(), $asOf);
    $before = PuCandidateGovernanceFixture::counts();

    expect($homologation->action)->toBe(PuNumericHomologationService::ACTION_READY_FOR_REVIEW);

    $result = app(PuCandidateCurvePersistenceService::class)->write($emission->fresh(), $asOf, $maker->email);
    $candidate = EmissionPuCurveVersion::query()->whereKey($result->candidateVersionId)->firstOrFail();
    $after = PuCandidateGovernanceFixture::counts();

    expect($result->action)->toBe(PuCandidateCurvePersistenceService::ACTION_PERSISTED)
        ->and($result->writes)->toBe($homologation->candidate->rowCount + 1)
        ->and($candidate->curve_role)->toBe(PuCurveRole::Candidate)
        ->and($candidate->review_status)->toBe(PuCurveReviewStatus::PendingReview)
        ->and($candidate->internal_validation_status)->toBe(PuCurveInternalValidationStatus::Passed)
        ->and($candidate->external_validation_status)->toBe(PuCurveExternalValidationStatus::Pending)
        ->and($candidate->candidate_as_of->toDateString())->toBe($asOf->toDateString())
        ->and($candidate->input_fingerprint)->toBe($homologation->plan->inputFingerprint)
        ->and($candidate->curve_checksum)->toBe($homologation->candidate->checksum)
        ->and($candidate->generated_by)->toBe($maker->id)
        ->and($candidate->reviewed_by)->toBeNull()
        ->and($candidate->rows_count)->toBe($homologation->candidate->rowCount)
        ->and($candidate->dailyCurves()->count())->toBe($homologation->candidate->rowCount)
        ->and($candidate->dailyCurves()->where('curve_version_id', '!=', $candidate->id)->count())->toBe(0)
        ->and($candidate->calculation_version)->toBe('v2')
        // Zero efeito operacional.
        ->and($emission->fresh()->latestPuCurveVersion()->first()?->id)->toBe($operational->id)
        ->and(EmissionPuDailyCurve::latestCalculationVersionForEmission($emission->id))->toBe('v1')
        ->and($emission->fresh()->operationalPuDailyCurves()->count())->toBe(1)
        ->and($after['operational_versions'])->toBe($before['operational_versions'])
        ->and($after['candidate_versions'])->toBe($before['candidate_versions'] + 1)
        ->and($after['parameters'])->toBe($before['parameters'])
        ->and($after['rates'])->toBe($before['rates'])
        ->and($after['events'])->toBe($before['events'])
        ->and(PuHistory::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0);
});

it('reproduces the candidate checksum from the persisted rows', function () {
    $asOf = PuCandidateGovernanceFixture::asOf();
    $emission = PuCandidateGovernanceFixture::readyEmission($asOf);
    $maker = PuCandidateGovernanceFixture::maker();
    $homologation = app(PuNumericHomologationService::class)->evaluate($emission->fresh(), $asOf);

    $result = app(PuCandidateCurvePersistenceService::class)->write($emission->fresh(), $asOf, $maker->email);
    $candidate = EmissionPuCurveVersion::query()->whereKey($result->candidateVersionId)->firstOrFail();
    $checksums = app(PuPersistedCurveChecksumService::class);

    expect($result->action)->toBe(PuCandidateCurvePersistenceService::ACTION_PERSISTED)
        ->and($checksums->checksumForRows($homologation->candidate->rows))->toBe($homologation->candidate->checksum)
        ->and($checksums->checksum($candidate))->toBe($homologation->candidate->checksum);
});

it('returns already_persisted without writing anything on an identical second attempt', function () {
    $asOf = PuCandidateGovernanceFixture::asOf();
    $emission = PuCandidateGovernanceFixture::readyEmission($asOf);
    $maker = PuCandidateGovernanceFixture::maker();
    $persistence = app(PuCandidateCurvePersistenceService::class);

    $first = $persistence->write($emission->fresh(), $asOf, $maker->email);
    $before = PuCandidateGovernanceFixture::counts();
    $second = $persistence->write($emission->fresh(), $asOf, $maker->email);

    expect($first->action)->toBe(PuCandidateCurvePersistenceService::ACTION_PERSISTED)
        ->and($second->action)->toBe(PuCandidateCurvePersistencePlanService::ACTION_ALREADY_PERSISTED)
        ->and($second->writes)->toBe(0)
        ->and($second->candidateVersionId)->toBe($first->candidateVersionId)
        ->and(PuCandidateGovernanceFixture::counts())->toBe($before);
});

it('appends a new candidate and preserves the previous one when the inputs legitimately change', function () {
    $asOf = PuCandidateGovernanceFixture::asOf();
    $emission = PuCandidateGovernanceFixture::readyEmission($asOf);
    $maker = PuCandidateGovernanceFixture::maker();
    $persistence = app(PuCandidateCurvePersistenceService::class);

    $first = $persistence->write($emission->fresh(), $asOf, $maker->email);
    $original = EmissionPuCurveVersion::query()->whereKey($first->candidateVersionId)->firstOrFail();
    $snapshot = [
        'fingerprint' => $original->input_fingerprint,
        'checksum' => $original->curve_checksum,
        'review' => $original->review_status,
        'maker' => $original->generated_by,
        'rows' => $original->dailyCurves()->count(),
    ];

    // Mudança legítima de insumo: uma taxa obrigatória passa a ter outro valor.
    candidatePersistenceChangeLastRate('15.10000000');

    $second = $persistence->write($emission->fresh(), $asOf, $maker->email);
    $appended = EmissionPuCurveVersion::query()->whereKey($second->candidateVersionId)->firstOrFail();
    $preserved = $original->fresh();

    expect($second->action)->toBe(PuCandidateCurvePersistenceService::ACTION_PERSISTED)
        ->and($appended->id)->not->toBe($original->id)
        ->and($appended->input_fingerprint)->not->toBe($snapshot['fingerprint'])
        ->and($appended->curve_role)->toBe(PuCurveRole::Candidate)
        ->and($preserved->input_fingerprint)->toBe($snapshot['fingerprint'])
        ->and($preserved->curve_checksum)->toBe($snapshot['checksum'])
        ->and($preserved->review_status)->toBe($snapshot['review'])
        ->and($preserved->generated_by)->toBe($snapshot['maker'])
        ->and($preserved->dailyCurves()->count())->toBe($snapshot['rows'])
        ->and(EmissionPuCurveVersion::query()->candidate()->count())->toBe(2);
});

it('aborts with zero writes when the inputs change between the plan and the transaction', function () {
    $asOf = PuCandidateGovernanceFixture::asOf();
    $emission = PuCandidateGovernanceFixture::readyEmission($asOf);
    $maker = PuCandidateGovernanceFixture::maker();
    $spy = app()->make(PuCandidatePersistencePlanSpy::class);
    $spy->mutateInsideTransaction = function (): void {
        candidatePersistenceChangeLastRate('15.55000000');
    };
    app()->instance(PuNumericHomologationPlanService::class, $spy);
    $before = PuCandidateGovernanceFixture::counts();

    $result = app(PuCandidateCurvePersistenceService::class)->write($emission->fresh(), $asOf, $maker->email);

    expect($result->action)->toBe(PuCandidateCurvePersistenceService::ACTION_STATE_CHANGED)
        ->and($result->writes)->toBe(0)
        ->and(PuCandidateGovernanceFixture::counts())->toBe($before);
});

it('rolls the whole transaction back when the persisted checksum does not match the candidate', function () {
    $asOf = PuCandidateGovernanceFixture::asOf();
    $emission = PuCandidateGovernanceFixture::readyEmission($asOf);
    $maker = PuCandidateGovernanceFixture::maker();
    app()->instance(
        PuPersistedCurveChecksumService::class,
        app()->make(PuCandidateCorruptChecksumSpy::class),
    );
    $before = PuCandidateGovernanceFixture::counts();

    $result = app(PuCandidateCurvePersistenceService::class)->write($emission->fresh(), $asOf, $maker->email);

    expect($result->action)->toBe(PuCandidateCurvePersistenceService::ACTION_INTEGRITY_FAILED)
        ->and($result->writes)->toBe(0)
        ->and(PuCandidateGovernanceFixture::counts())->toBe($before)
        ->and(EmissionPuCurveVersion::query()->candidate()->count())->toBe(0);
});

it('keeps the persisted candidate reportable for the governance dossier', function () {
    $emission = candidatePersistenceEmission();
    $candidate = candidatePersistenceCandidateVersion($emission, 'v1', '2026-01-02');

    $dossier = app(PuHomologationReportService::class)->build($candidate);

    expect($dossier)->toHaveKey('version')
        ->and($dossier['version']['calculation_version'] ?? null)->toBe('v1');
});

it('exposes the candidate dossier route only to a reviewer', function () {
    $emission = candidatePersistenceEmission();
    $candidate = candidatePersistenceCandidateVersion($emission, 'v1', '2026-01-02');
    $operational = candidatePersistenceOperationalVersion($emission, 'v2', '2026-01-03');
    $exporter = candidatePersistenceWebUser([AccessPermission::PuCurveExport->value]);
    $reviewer = candidatePersistenceWebUser([
        AccessPermission::PuCurveExport->value,
        AccessPermission::PuCurveHomologate->value,
    ]);
    $candidateRoute = route('admin.emissions.pu-homologation.pdf', [
        'emission' => $emission->id,
        'version' => $candidate->id,
    ]);
    $operationalRoute = route('admin.emissions.pu-homologation.pdf', [
        'emission' => $emission->id,
        'version' => $operational->id,
    ]);

    $this->actingAs($exporter)->get($candidateRoute)->assertNotFound();
    $this->actingAs($exporter)->get($operationalRoute)->assertSuccessful();
    $this->actingAs($reviewer)->get($candidateRoute)->assertSuccessful();
});
