<?php

use App\Actions\Emissions\GeneratePuDailyCurve;
use App\Actions\Emissions\HomologatePuCurve;
use App\Actions\Emissions\InvalidatePuCurve;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Exceptions\PuCurveGovernanceException;
use App\Domain\PuCalculator\Exceptions\PuMakerCheckerException;
use App\Domain\PuCalculator\Services\IndexRateLookupService;
use App\Domain\PuCalculator\Services\IndexRateService;
use App\Domain\PuCalculator\Services\PuCurveExtensionService;
use App\Domain\PuCalculator\Services\PuCurveVersionService;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Jobs\ExtendPuDailyCurveJob;
use App\Jobs\GeneratePuDailyCurveJob;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuEvent;
use App\Models\EmissionPuObligation;
use App\Models\EmissionPuObligationCalculation;
use App\Models\IndexRate;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

/**
 * Fase 2 -- governança da curva de PU.
 *
 * Só a homologação explícita de uma versão nomeada torna uma curva oficial; uma
 * tentativa com erro ou em processamento não desloca a versão utilizável; e o
 * status visto pela tela nunca é tomado como final.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function governancePublishCdi(string $from, string $to, string $rate = '14.90000000'): void
{
    for ($date = CarbonImmutable::parse($from); $date->lte(CarbonImmutable::parse($to)); $date = $date->addDay()) {
        if ($date->isWeekend()) {
            continue;
        }

        IndexRate::query()->updateOrCreate(
            ['indexer' => PuIndexer::Cdi->value, 'rate_date' => $date->toDateString()],
            ['rate_value' => $rate, 'source' => 'testing', 'source_reference' => 'governance'],
        );
    }

    app(IndexRateService::class)->flushCache();
    app(IndexRateLookupService::class)->flushCache();
}

/**
 * Emissão CDI com 100 títulos desde 02/03/2026 e cupom de juros de 08/03 pago em
 * 09/03, pronta para gerar curva real até 13/03.
 */
function governanceEmission(): Emission
{
    $emission = Emission::factory()->active()->create(['type' => 'CRI']);
    $emission->integralizationHistories()->create([
        'date' => '2026-03-02',
        'quantity' => '100.0000',
        'unit_value' => '1000.00000000',
        'financial_value' => '100000.00',
        'investor_fund' => 'Head Invest',
    ]);
    $emission->puParameter()->create([
        'curve_start_date' => '2026-03-02',
        'curve_end_date' => '2026-12-31',
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '6.00000000',
        'indexer' => PuIndexer::Cdi->value,
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'index_rate_lookup_mode' => PuIndexRateLookupMode::BusinessDayLagExact->value,
        'index_rate_lag_business_days' => -1,
        'legacy_projection_enabled' => true,
    ]);
    EmissionPuEvent::query()->create([
        'emission_id' => $emission->id,
        'event_type' => PuEventType::InterestPayment->value,
        'original_date' => '2026-03-08',
        'effective_date' => '2026-03-09',
        'amortization_type' => PuAmortizationType::None->value,
        'amortization_value' => null,
        'sequence' => 1,
    ]);
    governancePublishCdi('2026-02-27', '2026-03-13');

    return $emission->fresh();
}

function governanceGenerate(Emission $emission): EmissionPuCurveVersion
{
    $result = app(GeneratePuDailyCurve::class)->handle($emission->fresh());

    return EmissionPuCurveVersion::query()
        ->where('emission_id', $emission->id)
        ->where('calculation_version', $result->calculationVersion)
        ->sole();
}

function governanceVersion(Emission $emission, string $calculationVersion, PuCurveStatus $status, ?int $generatedBy = null): EmissionPuCurveVersion
{
    return EmissionPuCurveVersion::factory()->create([
        'emission_id' => $emission->id,
        'calculation_version' => $calculationVersion,
        'status' => $status->value,
        'generated_by' => $generatedBy,
    ]);
}

function governanceChecker(): User
{
    return User::factory()->create();
}

/**
 * Faz a atualização das obrigações falhar no meio, ao gravar o cálculo esperado
 * (Fase 5): o mesmo efeito de um erro de banco depois de a versão já ter mudado
 * de status.
 */
function governanceFailObligationWrites(): void
{
    EmissionPuObligationCalculation::saving(function (): never {
        throw new RuntimeException('Falha na conciliação.');
    });
}

// ---------------------------------------------------------------------------
// Máquina de estados
// ---------------------------------------------------------------------------

it('declares exactly the governed transitions of the curve lifecycle', function (PuCurveStatus $from, PuCurveStatus $to, bool $allowed) {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with([
    'processing → generated' => [PuCurveStatus::Processing, PuCurveStatus::Generated, true],
    'processing → error' => [PuCurveStatus::Processing, PuCurveStatus::Error, true],
    'processing → homologated' => [PuCurveStatus::Processing, PuCurveStatus::Homologated, false],
    'processing → validated' => [PuCurveStatus::Processing, PuCurveStatus::Validated, false],
    'generated → validated' => [PuCurveStatus::Generated, PuCurveStatus::Validated, true],
    'generated → divergent' => [PuCurveStatus::Generated, PuCurveStatus::Divergent, true],
    'generated → homologated (checker distinto)' => [PuCurveStatus::Generated, PuCurveStatus::Homologated, true],
    'generated → error' => [PuCurveStatus::Generated, PuCurveStatus::Error, true],
    'validated → homologated' => [PuCurveStatus::Validated, PuCurveStatus::Homologated, true],
    'validated → error' => [PuCurveStatus::Validated, PuCurveStatus::Error, false],
    'divergent → homologated (com justificativa)' => [PuCurveStatus::Divergent, PuCurveStatus::Homologated, true],
    'homologated → obsolete (invalidação)' => [PuCurveStatus::Homologated, PuCurveStatus::Obsolete, true],
    'homologated → generated' => [PuCurveStatus::Homologated, PuCurveStatus::Generated, false],
    'homologated → validated' => [PuCurveStatus::Homologated, PuCurveStatus::Validated, false],
    'error → generated' => [PuCurveStatus::Error, PuCurveStatus::Generated, false],
    'error → homologated' => [PuCurveStatus::Error, PuCurveStatus::Homologated, false],
    'obsolete → generated' => [PuCurveStatus::Obsolete, PuCurveStatus::Generated, false],
    'obsolete → validated' => [PuCurveStatus::Obsolete, PuCurveStatus::Validated, false],
    'obsolete → homologated' => [PuCurveStatus::Obsolete, PuCurveStatus::Homologated, false],
]);

it('takes a version from processing to generated and from processing to error', function () {
    $emission = governanceEmission();
    $versions = app(PuCurveVersionService::class);

    $generated = $versions->startGeneration($emission, null);
    $versions->markGenerated($generated, 10);
    $failed = $versions->startGeneration($emission, null);
    $versions->markError($failed, 'Falha numérica.');

    expect($generated->fresh()->status)->toBe(PuCurveStatus::Generated)
        ->and($generated->fresh()->rows_count)->toBe(10)
        ->and($failed->fresh()->status)->toBe(PuCurveStatus::Error)
        ->and($failed->fresh()->error_message)->toBe('Falha numérica.');
});

it('takes a generated version through validation to homologation', function () {
    $emission = governanceEmission();
    $version = governanceGenerate($emission);

    app(PuCurveVersionService::class)->markValidated($version, true, ['status' => 'approved'], governanceChecker()->id);
    expect($version->fresh()->status)->toBe(PuCurveStatus::Validated);

    app(HomologatePuCurve::class)->handle($emission, 'v1', governanceChecker()->id, 'Curva da rotina conferida.');

    expect($version->fresh()->status)->toBe(PuCurveStatus::Homologated)
        ->and($emission->fresh()->officialPuCurveVersion()?->id)->toBe($version->id);
});

it('refuses to homologate a version that is not a finished, living calculation', function (PuCurveStatus $status) {
    $emission = governanceEmission();
    $version = governanceVersion($emission, 'v1', $status, governanceChecker()->id);

    expect(fn () => app(HomologatePuCurve::class)->handle($emission, 'v1', governanceChecker()->id, 'Tentativa.'))
        ->toThrow(PuCurveGovernanceException::class, 'não pode ser homologada');

    expect($version->fresh()->status)->toBe($status)
        ->and(Activity::query()->where('description', 'pu_curve_homologated')->exists())->toBeFalse();
})->with([
    'processing' => [PuCurveStatus::Processing],
    'error' => [PuCurveStatus::Error],
    'obsolete (invalidada ou substituída)' => [PuCurveStatus::Obsolete],
    'já homologada' => [PuCurveStatus::Homologated],
]);

it('refuses to record a validation result on a version that left the lifecycle', function (PuCurveStatus $status) {
    $emission = governanceEmission();
    $version = governanceVersion($emission, 'v1', $status);

    expect(fn () => app(PuCurveVersionService::class)->markValidated($version, true, ['status' => 'approved'], null))
        ->toThrow(PuCurveGovernanceException::class);

    expect($version->fresh()->status)->toBe($status)
        ->and($version->fresh()->validated_at)->toBeNull();
})->with([
    'obsolete' => [PuCurveStatus::Obsolete],
    'error' => [PuCurveStatus::Error],
    'processing' => [PuCurveStatus::Processing],
]);

it('keeps a homologated version homologated when it is validated again', function () {
    $emission = governanceEmission();
    $version = governanceVersion($emission, 'v1', PuCurveStatus::Homologated);

    app(PuCurveVersionService::class)->markValidated($version, false, ['status' => 'rejected'], null);

    expect($version->fresh()->status)->toBe(PuCurveStatus::Homologated)
        ->and($version->fresh()->validation_summary)->toBe(['status' => 'rejected']);
});

it('marks a version obsolete when invalidated and keeps it for audit', function () {
    $emission = governanceEmission();
    $version = governanceGenerate($emission);

    app(InvalidatePuCurve::class)->handle($emission, 'v1', governanceChecker()->id, 'Parâmetro de spread errado.');

    $fresh = $version->fresh();

    expect($fresh->status)->toBe(PuCurveStatus::Obsolete)
        ->and($fresh->obsolete_reason)->toBe('invalidated')
        ->and($fresh->dailyCurves()->count())->toBeGreaterThan(0)
        ->and(fn () => app(HomologatePuCurve::class)->handle($emission, 'v1', governanceChecker()->id, 'Tentar de novo.'))
        ->toThrow(PuCurveGovernanceException::class);
});

// ---------------------------------------------------------------------------
// Vigente × oficial × última tentativa
// ---------------------------------------------------------------------------

it('never lets a failed or running attempt displace the usable version', function () {
    $emission = governanceEmission();
    $usable = governanceGenerate($emission);
    app(HomologatePuCurve::class)->handle($emission, 'v1', governanceChecker()->id, 'Conferida.');
    $versions = app(PuCurveVersionService::class);

    $failed = $versions->startGeneration($emission, null);
    $versions->markError($failed, 'O Newton não convergiu.');

    expect($emission->fresh()->currentPuCurveVersion()?->id)->toBe($usable->id)
        ->and($emission->fresh()->officialPuCurveVersion()?->id)->toBe($usable->id)
        ->and($emission->fresh()->latestPuCurveVersion()->first()?->id)->toBe($failed->id);

    $running = $versions->startGeneration($emission, null);

    expect($emission->fresh()->currentPuCurveVersion()?->id)->toBe($usable->id)
        ->and($emission->fresh()->latestPuCurveVersion()->first()?->id)->toBe($running->id);
});

it('keeps extending the homologated version after a newer attempt failed', function () {
    $emission = governanceEmission();
    $usable = governanceGenerate($emission);
    app(HomologatePuCurve::class)->handle($emission, 'v1', governanceChecker()->id, 'Conferida.');
    $versions = app(PuCurveVersionService::class);
    $versions->markError($versions->startGeneration($emission, null), 'Falha na regeneração.');
    $rowsBefore = $usable->dailyCurves()->count();
    governancePublishCdi('2026-03-16', '2026-03-20');

    $result = app(PuCurveExtensionService::class)->extend($emission->fresh());

    expect($result->action)->toBe(PuCurveExtensionService::ACTION_EXTENDED)
        ->and($result->versionId)->toBe($usable->id)
        ->and($usable->dailyCurves()->count())->toBe($rowsBefore + $result->appendedRows);
});

it('does not queue a new full generation over a failed or running attempt', function (PuCurveStatus $status) {
    Queue::fake();
    $emission = governanceEmission();
    governanceVersion($emission, 'v1', $status);

    $this->artisan('pu:curves:generate-realized', ['--emission' => $emission->id])->assertSuccessful();

    Queue::assertNotPushed(GeneratePuDailyCurveJob::class);
    Queue::assertNotPushed(ExtendPuDailyCurveJob::class);
})->with([
    'error' => [PuCurveStatus::Error],
    'processing' => [PuCurveStatus::Processing],
]);

it('has no official curve until one version is homologated', function (PuCurveStatus $status) {
    $emission = governanceEmission();
    governanceVersion($emission, 'v1', $status);

    expect($emission->fresh()->officialPuCurveVersion())->toBeNull();
})->with([
    'processing' => [PuCurveStatus::Processing],
    'generated' => [PuCurveStatus::Generated],
    'validated' => [PuCurveStatus::Validated],
    'divergent' => [PuCurveStatus::Divergent],
    'error' => [PuCurveStatus::Error],
    'obsolete' => [PuCurveStatus::Obsolete],
]);

// ---------------------------------------------------------------------------
// Alvo explícito
// ---------------------------------------------------------------------------

it('refuses governance actions that do not name the version', function () {
    $emission = governanceEmission();
    governanceGenerate($emission);

    expect(fn () => app(HomologatePuCurve::class)->handle($emission, null, governanceChecker()->id, 'Sem versão.'))
        ->toThrow(PuCurveGovernanceException::class, 'nunca escolhem a versão sozinhas')
        ->and(fn () => app(InvalidatePuCurve::class)->handle($emission, null, governanceChecker()->id))
        ->toThrow(PuCurveGovernanceException::class, 'nunca escolhem a versão sozinhas')
        ->and($emission->fresh()->currentPuCurveVersion()->status)->toBe(PuCurveStatus::Generated);
});

it('homologates v3 -- and only v3 -- when v4 appears while v3 is being reviewed', function () {
    $checker = makeAdminUser();
    $emission = governanceEmission();
    governanceVersion($emission, 'v1', PuCurveStatus::Obsolete);
    governanceVersion($emission, 'v2', PuCurveStatus::Obsolete);
    $reviewed = governanceVersion($emission, 'v3', PuCurveStatus::Validated, User::factory()->create()->id);
    $this->actingAs($checker);

    $page = Livewire::test(EditEmission::class, ['record' => $emission->getRouteKey()])
        ->mountAction('homologatePuCurve')
        ->assertActionDataSet(['calculation_version' => 'v3']);

    // Outro processo inicia a v4 enquanto o modal da v3 está aberto.
    $concurrent = app(PuCurveVersionService::class)->startGeneration($emission, null);

    $page->callMountedAction(['justification' => ''])->assertHasNoActionErrors();

    expect($concurrent->calculation_version)->toBe('v4')
        ->and($reviewed->fresh()->status)->toBe(PuCurveStatus::Homologated)
        ->and($concurrent->fresh()->status)->toBe(PuCurveStatus::Processing)
        ->and($emission->fresh()->officialPuCurveVersion()?->calculation_version)->toBe('v3');
});

it('homologates the reviewed v3 even when a usable v4 became the current version meanwhile', function () {
    $checker = makeAdminUser();
    $emission = governanceEmission();
    $reviewed = governanceVersion($emission, 'v3', PuCurveStatus::Validated, User::factory()->create()->id);
    $this->actingAs($checker);

    $page = Livewire::test(EditEmission::class, ['record' => $emission->getRouteKey()])
        ->mountAction('homologatePuCurve')
        ->assertActionDataSet(['calculation_version' => 'v3']);

    // A v4 passa a ser a versão de trabalho vigente antes da confirmação.
    $newer = governanceVersion($emission, 'v4', PuCurveStatus::Generated, User::factory()->create()->id);
    expect($emission->fresh()->currentPuCurveVersion()?->id)->toBe($newer->id);

    $page->callMountedAction(['justification' => ''])->assertHasNoActionErrors();

    expect($reviewed->fresh()->status)->toBe(PuCurveStatus::Homologated)
        ->and($newer->fresh()->status)->toBe(PuCurveStatus::Generated)
        ->and($emission->fresh()->officialPuCurveVersion()?->id)->toBe($reviewed->id);
});

it('refuses on screen the reviewed v3, and never homologates v4, when v4 superseded it', function () {
    $checker = makeAdminUser();
    $emission = governanceEmission();
    $reviewed = governanceVersion($emission, 'v3', PuCurveStatus::Validated, User::factory()->create()->id);
    $this->actingAs($checker);

    $page = Livewire::test(EditEmission::class, ['record' => $emission->getRouteKey()])
        ->mountAction('homologatePuCurve');

    $versions = app(PuCurveVersionService::class);
    $newer = $versions->startGeneration($emission, User::factory()->create()->id);
    $versions->markGenerated($newer, 5);

    $page->callMountedAction(['justification' => 'Conferida.'])
        ->assertNotified('Nao foi possivel homologar.');

    expect($reviewed->fresh()->status)->toBe(PuCurveStatus::Obsolete)
        ->and($newer->fresh()->status)->toBe(PuCurveStatus::Generated)
        ->and($emission->fresh()->officialPuCurveVersion())->toBeNull();
});

it('refuses the reviewed v3 instead of homologating v4 when v4 superseded it', function () {
    $emission = governanceEmission();
    $reviewed = governanceVersion($emission, 'v3', PuCurveStatus::Generated, User::factory()->create()->id);
    $versions = app(PuCurveVersionService::class);

    // A v4 conclui depois que a v3 foi revisada: a v3 vira obsoleta (substituída).
    $newer = $versions->startGeneration($emission, null);
    $versions->markGenerated($newer, 5);

    expect(fn () => app(HomologatePuCurve::class)->handle($emission, 'v3', governanceChecker()->id))
        ->toThrow(PuCurveGovernanceException::class, 'não pode ser homologada');

    expect($reviewed->fresh()->status)->toBe(PuCurveStatus::Obsolete)
        ->and($newer->fresh()->status)->toBe(PuCurveStatus::Generated)
        ->and($emission->fresh()->officialPuCurveVersion())->toBeNull();
});

it('invalidates the reviewed version, not the newer one, when the modal outlives a regeneration', function () {
    $admin = makeAdminUser();
    $emission = governanceEmission();
    $reviewed = governanceVersion($emission, 'v1', PuCurveStatus::Homologated);
    $this->actingAs($admin);

    $page = Livewire::test(EditEmission::class, ['record' => $emission->getRouteKey()])
        ->mountAction('invalidatePuCurve')
        ->assertActionDataSet(['calculation_version' => 'v1']);

    $newer = governanceVersion($emission, 'v2', PuCurveStatus::Generated);

    $page->callMountedAction(['reason' => 'Evento de amortização errado.'])->assertHasNoActionErrors();

    expect($reviewed->fresh()->status)->toBe(PuCurveStatus::Obsolete)
        ->and($newer->fresh()->status)->toBe(PuCurveStatus::Generated);
});

// ---------------------------------------------------------------------------
// Invalidação × geração
// ---------------------------------------------------------------------------

it('refuses to invalidate a version that is still being generated', function () {
    $emission = governanceEmission();
    $running = app(PuCurveVersionService::class)->startGeneration($emission, null);

    expect(fn () => app(InvalidatePuCurve::class)->handle($emission, $running->calculation_version, governanceChecker()->id))
        ->toThrow(PuCurveGovernanceException::class, 'Aguarde a geração terminar');

    expect($running->fresh()->status)->toBe(PuCurveStatus::Processing);
});

it('never resurrects a version that left processing while it was being generated', function () {
    $emission = governanceEmission();
    $versions = app(PuCurveVersionService::class);
    $running = $versions->startGeneration($emission, null);

    // Saiu de cena no meio do cálculo (substituída por outra geração).
    $running->forceFill(['status' => PuCurveStatus::Obsolete, 'obsolete_reason' => 'superseded'])->save();
    $stale = EmissionPuCurveVersion::query()->findOrFail($running->id);
    $stale->status = PuCurveStatus::Processing;
    $stale->syncOriginalAttribute('status');

    expect(fn () => $versions->markGenerated($stale, 10))
        ->toThrow(PuCurveGovernanceException::class, 'não está mais em processamento');

    $versions->markError($stale, 'Falha depois de substituída.');

    expect($running->fresh()->status)->toBe(PuCurveStatus::Obsolete)
        ->and($running->fresh()->error_message)->toBeNull();
});

it('drops the rows of a generation whose version was invalidated meanwhile', function () {
    $emission = governanceEmission();
    $job = new GeneratePuDailyCurveJob($emission->id, User::factory()->create()->id);

    // Simula a invalidação entre o início da geração e a gravação das linhas.
    app()->instance(PuCurveVersionService::class, new class extends PuCurveVersionService
    {
        public function startGeneration(Emission $emission, ?int $requestedByUserId, array $parametersSnapshot = [], ?string $calculationVersion = null): EmissionPuCurveVersion
        {
            $version = parent::startGeneration($emission, $requestedByUserId, $parametersSnapshot, $calculationVersion);
            EmissionPuCurveVersion::query()->whereKey($version->id)->update(['status' => PuCurveStatus::Obsolete->value, 'obsolete_reason' => 'invalidated']);

            return $version;
        }
    });

    expect(fn () => app()->call([$job, 'handle']))->toThrow(PuCurveGovernanceException::class);

    $version = EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->sole();

    expect($version->status)->toBe(PuCurveStatus::Obsolete)
        ->and($version->dailyCurves()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Concorrência e transação
// ---------------------------------------------------------------------------

it('lets only the first of two homologations of the same version through', function () {
    $emission = governanceEmission();
    $version = governanceGenerate($emission);

    app(HomologatePuCurve::class)->handle($emission, 'v1', governanceChecker()->id, 'Primeira.');

    expect(fn () => app(HomologatePuCurve::class)->handle($emission, 'v1', governanceChecker()->id, 'Segunda.'))
        ->toThrow(PuCurveGovernanceException::class, 'não pode ser homologada');

    expect($version->fresh()->homologation_justification)->toBe('Primeira.')
        ->and(Activity::query()->where('description', 'pu_curve_homologated')->count())->toBe(1);
});

it('rolls the homologation back when the obligation update fails', function () {
    $emission = governanceEmission();
    $version = governanceGenerate($emission);
    Payment::query()->create([
        'emission_id' => $emission->id,
        'payment_date' => '2026-03-09',
        'premium_value' => '0.00',
        'interest_value' => '999.00',
        'amortization_value' => '0.00',
        'extra_amortization_value' => '0.00',
    ]);
    governanceFailObligationWrites();

    expect(fn () => app(HomologatePuCurve::class)->handle($emission, 'v1', governanceChecker()->id, 'Conferida.'))
        ->toThrow(RuntimeException::class, 'Falha na conciliação.');

    $payment = Payment::query()->whereBelongsTo($emission)->sole();

    expect($version->fresh()->status)->toBe(PuCurveStatus::Generated)
        ->and($version->fresh()->homologated_at)->toBeNull()
        ->and($emission->fresh()->officialPuCurveVersion())->toBeNull()
        ->and(Activity::query()->where('description', 'pu_curve_homologated')->exists())->toBeFalse()
        ->and(EmissionPuObligation::query()->where('emission_id', $emission->id)->exists())->toBeFalse()
        ->and((string) $payment->interest_value)->toBe('999.00');
});

it('rolls the invalidation back when the obligation update fails', function () {
    $emission = governanceEmission();
    $version = governanceGenerate($emission);
    app(HomologatePuCurve::class)->handle($emission, 'v1', governanceChecker()->id, 'Conferida.');
    $obligation = EmissionPuObligation::query()->where('emission_id', $emission->id)->sole();
    $calculation = $obligation->currentCalculation;
    governanceFailObligationWrites();

    expect(fn () => app(InvalidatePuCurve::class)->handle($emission, 'v1', governanceChecker()->id))
        ->toThrow(RuntimeException::class, 'Falha na conciliação.');

    expect($calculation->fresh()->superseded_at)->toBeNull()
        ->and($obligation->fresh()->current_calculation_id)->toBe($calculation->id)
        ->and($version->fresh()->status)->toBe(PuCurveStatus::Homologated)
        ->and($emission->fresh()->officialPuCurveVersion()?->id)->toBe($version->id)
        ->and(Activity::query()->where('description', 'pu_curve_invalidated')->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Elegibilidade e justificativa
// ---------------------------------------------------------------------------

it('requires a justification to homologate a divergent curve or one generated by the routine', function (PuCurveStatus $status, bool $automated) {
    $emission = governanceEmission();
    $version = governanceVersion($emission, 'v1', $status, $automated ? null : User::factory()->create()->id);

    expect(fn () => app(HomologatePuCurve::class)->handle($emission, 'v1', governanceChecker()->id))
        ->toThrow(PuMakerCheckerException::class, 'justificativa');

    app(HomologatePuCurve::class)->handle($emission, 'v1', governanceChecker()->id, '  Diferenças dentro da tolerância da B3.  ');

    expect($version->fresh()->status)->toBe(PuCurveStatus::Homologated)
        ->and($version->fresh()->homologation_justification)->toBe('Diferenças dentro da tolerância da B3.');
})->with([
    'divergente, maker humano' => [PuCurveStatus::Divergent, false],
    'gerada pela rotina' => [PuCurveStatus::Generated, true],
    'validada, gerada pela rotina' => [PuCurveStatus::Validated, true],
]);

it('keeps the official path of a curve generated by one person and homologated by another', function () {
    $emission = governanceEmission();
    $version = governanceVersion($emission, 'v1', PuCurveStatus::Generated, User::factory()->create()->id);

    app(HomologatePuCurve::class)->handle($emission, 'v1', governanceChecker()->id);

    expect($version->fresh()->status)->toBe(PuCurveStatus::Homologated)
        ->and($version->fresh()->homologation_justification)->toBeNull();
});

// ---------------------------------------------------------------------------
// Auditoria
// ---------------------------------------------------------------------------

it('records the version, the actor, the transition and the reason of every governance act', function () {
    $emission = governanceEmission();
    $version = governanceGenerate($emission);
    $checker = governanceChecker();

    app(HomologatePuCurve::class)->handle($emission, 'v1', $checker->id, 'Conferida.');
    app(InvalidatePuCurve::class)->handle($emission, 'v1', $checker->id, 'CDI de 05/03 corrigido.');

    $homologation = Activity::query()->where('description', 'pu_curve_homologated')->sole();
    $invalidation = Activity::query()->where('description', 'pu_curve_invalidated')->sole();

    expect($homologation->causer_id)->toBe($checker->id)
        ->and($homologation->properties['curve_version_id'])->toBe($version->id)
        ->and($homologation->properties['calculation_version'])->toBe('v1')
        ->and($homologation->properties['previous_status'])->toBe('generated')
        ->and($homologation->properties['new_status'])->toBe('homologated')
        ->and($homologation->properties['justification'])->toBe('Conferida.')
        ->and($invalidation->causer_id)->toBe($checker->id)
        ->and($invalidation->properties['curve_version_id'])->toBe($version->id)
        ->and($invalidation->properties['previous_status'])->toBe('homologated')
        ->and($invalidation->properties['new_status'])->toBe('obsolete')
        ->and($invalidation->properties['reason'])->toBe('CDI de 05/03 corrigido.');
});
