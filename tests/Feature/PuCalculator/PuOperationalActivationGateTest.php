<?php

use App\Actions\Emissions\GeneratePuDailyCurve;
use App\Actions\Emissions\HomologatePuCurve;
use App\Domain\PuCalculator\DTOs\PuSettlementData;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuObligationType;
use App\Domain\PuCalculator\Enums\PuOperationalActivationStatus;
use App\Domain\PuCalculator\Enums\PuOperationalConditionType;
use App\Domain\PuCalculator\Enums\PuOperationalEligibility;
use App\Domain\PuCalculator\Enums\PuOperationalSeverity;
use App\Domain\PuCalculator\Enums\PuSettlementOutcome;
use App\Domain\PuCalculator\Enums\PuSettlementSource;
use App\Domain\PuCalculator\Exceptions\PuOperationalActivationException;
use App\Domain\PuCalculator\Services\PuCurveExtensionService;
use App\Domain\PuCalculator\Services\PuCurveVersionService;
use App\Domain\PuCalculator\Services\PuOperationalActivationPolicy;
use App\Domain\PuCalculator\Services\PuOperationalHealthService;
use App\Domain\PuCalculator\Services\PuSettlementService;
use App\Domain\PuCalculator\Support\PuOperationsConfig;
use App\Jobs\ExtendPuDailyCurveJob;
use App\Jobs\GeneratePuDailyCurveJob;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuHistoryAnchor;
use App\Models\EmissionPuObligation;
use App\Models\EmissionPuSettlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Pu\PuObligationFixture as Fx;
use Tests\Support\Pu\PuOperationalActivationFixture as Activation;

/**
 * Fase 7 (P0) -- emissão `active` com parâmetros CDI não é emissão autorizada.
 *
 * Só entra no pipeline que grava curva, homologa e estende quem passa no
 * interruptor global do ambiente (desligado por padrão, fail-closed) E tem uma
 * decisão de ativação gravada. Rotina diária, job na fila, ação da tela, serviço
 * chamado direto, extensão, homologação e liquidação recebem a mesma recusa; a
 * checagem que vale é a feita na execução, sob a trava da emissão.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Activation::withoutAutomaticActivation();
});

/**
 * Emissão CDI ativa, configurada, com CDI e calendário -- todos os
 * pré-requisitos de dado presentes, para que a única recusa seja a da ativação.
 */
function p7gEmission(): Emission
{
    return Fx::emission([[PuEventType::InterestPayment, '2026-03-09']]);
}

/**
 * @return array{emission: Emission, official: EmissionPuCurveVersion}
 */
function p7gActivatedOfficial(): array
{
    $emission = p7gEmission();
    Activation::activate($emission->id);
    $official = Fx::official($emission);

    return ['emission' => $emission->fresh(), 'official' => $official->fresh()];
}

/**
 * @return array{versions: int, rows: int, anchors: int, obligations: int, settlements: int}
 */
function p7gCounts(): array
{
    return [
        'versions' => EmissionPuCurveVersion::query()->count(),
        'rows' => EmissionPuDailyCurve::query()->count(),
        'anchors' => EmissionPuHistoryAnchor::query()->count(),
        'obligations' => EmissionPuObligation::query()->count(),
        'settlements' => EmissionPuSettlement::query()->count(),
    ];
}

/**
 * Avalia o arquivo de configuração com a variável de ambiente dada (nula =
 * ausente) e devolve o valor já interpretado -- o mesmo que o `config:cache`
 * guardaria.
 */
function p7gConfigUnderEnv(?string $value): mixed
{
    $key = 'PU_OPERATIONS_ENABLED';
    $previous = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];

    try {
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        } else {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }

        $config = require config_path('pu_calculator.php');

        return $config['operations']['enabled'];
    } finally {
        [$env, $server, $getenv] = $previous;
        $env === null ? (function () use ($key): void {
            unset($_ENV[$key]);
        })() : $_ENV[$key] = $env;
        $server === null ? (function () use ($key): void {
            unset($_SERVER[$key]);
        })() : $_SERVER[$key] = $server;
        $getenv === false ? putenv($key) : putenv($key.'='.$getenv);
    }
}

it('derives the operational status: configured and active is still inactive until an explicit decision exists', function () {
    $policy = app(PuOperationalActivationPolicy::class);
    $configured = p7gEmission();
    $unconfigured = Emission::factory()->active()->create();

    expect($configured->status)->toBe('active')
        ->and($configured->puParameter->indexer)->toBe('CDI')
        ->and($policy->status($configured))->toBe(PuOperationalActivationStatus::Inactive)
        ->and($policy->status($unconfigured))->toBe(PuOperationalActivationStatus::NotConfigured)
        ->and($policy->allows($configured))->toBeFalse();

    Activation::activate($configured->id);
    expect($policy->status($configured))->toBe(PuOperationalActivationStatus::Activated)
        ->and($policy->allows($configured))->toBeTrue();

    Activation::suspend($configured->id);
    expect($policy->status($configured))->toBe(PuOperationalActivationStatus::Suspended)
        ->and($policy->allows($configured))->toBeFalse()
        ->and($policy->statuses([$configured->id, $unconfigured->id]))->toBe([
            $configured->id => PuOperationalActivationStatus::Suspended,
            $unconfigured->id => PuOperationalActivationStatus::NotConfigured,
        ]);
});

it('reads the global switch fail-closed: only an unambiguous affirmative enables PU operations', function (mixed $value, bool $enabled) {
    config([PuOperationsConfig::ENABLED_KEY => $value]);

    expect(PuOperationsConfig::flag($value))->toBe($enabled)
        ->and(PuOperationsConfig::enabled())->toBe($enabled)
        ->and(app(PuOperationalActivationPolicy::class)->globallyEnabled())->toBe($enabled);
})->with([
    'bool true' => [true, true],
    'text true' => ['true', true],
    'upper TRUE' => ['TRUE', true],
    'one' => ['1', true],
    'int one' => [1, true],
    'on' => ['on', true],
    'yes' => ['yes', true],
    'bool false' => [false, false],
    'text false' => ['false', false],
    'zero' => ['0', false],
    'off' => ['off', false],
    'no' => ['no', false],
    'empty' => ['', false],
    'typo' => ['treu', false],
    'portuguese' => ['ligado', false],
    'enabled word' => ['enabled', false],
    'int two' => [2, false],
    'null' => [null, false],
    'array' => [[], false],
]);

it('keeps the switch off when the environment variable is missing or malformed, and caches the interpreted boolean', function () {
    expect(p7gConfigUnderEnv(null))->toBeFalse()
        ->and(p7gConfigUnderEnv('off'))->toBeFalse()
        ->and(p7gConfigUnderEnv('no'))->toBeFalse()
        ->and(p7gConfigUnderEnv('enable'))->toBeFalse()
        ->and(p7gConfigUnderEnv(''))->toBeFalse()
        ->and(p7gConfigUnderEnv('true'))->toBeTrue()
        ->and(var_export(p7gConfigUnderEnv('off'), true))->toBe('false')
        ->and(var_export(p7gConfigUnderEnv('true'), true))->toBe('true');
});

it('queues no generation or extension from the daily routine while PU operations are disabled in the environment', function () {
    Queue::fake();
    config([PuOperationsConfig::ENABLED_KEY => false]);
    $emission = p7gEmission();
    Activation::activate($emission->id);

    $this->artisan('pu:curves:generate-realized')
        ->expectsOutputToContain('desligadas')
        ->assertSuccessful();

    Queue::assertNothingPushed();
    expect(EmissionPuCurveVersion::query()->count())->toBe(0);
});

it('never queues a first curve for an active CDI emission whose PU operation was never activated, and stops again on suspension', function () {
    Queue::fake();
    $emission = p7gEmission();

    $this->artisan('pu:curves:generate-realized')->expectsOutputToContain('1 sem operacao do PU ativada')->assertSuccessful();
    Queue::assertNothingPushed();

    Activation::activate($emission->id);
    $this->artisan('pu:curves:generate-realized')->assertSuccessful();
    Queue::assertPushed(GeneratePuDailyCurveJob::class, 1);

    Queue::fake();
    Activation::suspend($emission->id);
    $this->artisan('pu:curves:generate-realized')->assertSuccessful();
    Queue::assertNothingPushed();
    expect(EmissionPuCurveVersion::query()->count())->toBe(0);
});

it('rechecks the decision when a queued generation finally runs: a job queued before a suspension creates nothing', function () {
    $emission = p7gEmission();
    Activation::activate($emission->id);
    $job = new GeneratePuDailyCurveJob($emission->id);

    Activation::suspend($emission->id);
    app()->call([$job, 'handle']);

    $status = Cache::get(sprintf('pu_curve_generation_%d_status', $emission->id));

    expect(p7gCounts())->toBe(['versions' => 0, 'rows' => 0, 'anchors' => 0, 'obligations' => 0, 'settlements' => 0])
        ->and($status['status'])->toBe('failed')
        ->and($status['error'])->toContain(PuOperationalActivationException::REASON_SUSPENDED)
        ->and(Activity::query()->where('event', 'failed')->where('description', 'pu_curve_generation_failed')->exists())->toBeTrue();
});

it('rechecks the global switch when a queued generation runs after operations were turned off', function () {
    $emission = p7gEmission();
    Activation::activate($emission->id);
    $job = new GeneratePuDailyCurveJob($emission->id);

    config([PuOperationsConfig::ENABLED_KEY => false]);
    app()->call([$job, 'handle']);

    expect(EmissionPuCurveVersion::query()->count())->toBe(0)
        ->and(Cache::get(sprintf('pu_curve_generation_%d_status', $emission->id))['error'])->toContain(PuOperationalActivationException::REASON_GLOBALLY_DISABLED);
});

it('refuses direct generation through the action and the version service for a non-activated emission, writing nothing', function () {
    $emission = p7gEmission();

    expect(fn () => app(GeneratePuDailyCurve::class)->handle($emission))
        ->toThrow(PuOperationalActivationException::class, PuOperationalActivationException::REASON_NOT_ACTIVATED)
        ->and(fn () => app(PuCurveVersionService::class)->startGeneration($emission, null))
        ->toThrow(PuOperationalActivationException::class, PuOperationalActivationException::REASON_NOT_ACTIVATED)
        ->and(p7gCounts())->toBe(['versions' => 0, 'rows' => 0, 'anchors' => 0, 'obligations' => 0, 'settlements' => 0]);
});

it('lets an activated emission generate and blocks the same direct path as soon as it is suspended', function () {
    $emission = p7gEmission();
    Activation::activate($emission->id);

    $version = Fx::generate($emission);
    expect($version->status)->toBe(PuCurveStatus::Generated);

    Activation::suspend($emission->id);
    $before = p7gCounts();

    expect(fn () => app(GeneratePuDailyCurve::class)->handle($emission->fresh()))
        ->toThrow(PuOperationalActivationException::class, PuOperationalActivationException::REASON_SUSPENDED)
        ->and(p7gCounts())->toBe($before);
});

it('stops the official extension of a suspended emission without recording a failure on the official version, and resumes after reactivation', function () {
    ['emission' => $emission, 'official' => $official] = p7gActivatedOfficial();
    $lastDate = Fx::lastDate($official);
    Fx::publish('2026-03-16', '2026-03-20');
    Activation::suspend($emission->id);

    $result = app(PuCurveExtensionService::class)->extendOfficial($emission->fresh());

    expect($result->action)->toBe(PuCurveExtensionService::ACTION_OPERATIONS_NOT_ACTIVE)
        ->and($result->reason)->toContain(PuOperationalActivationException::REASON_SUSPENDED)
        ->and(Fx::lastDate($official->fresh()))->toBe($lastDate)
        ->and($official->fresh()->extension_failed_at)->toBeNull()
        ->and($official->fresh()->extension_diverged_at)->toBeNull();

    Activation::activate($emission->id);
    $resumed = app(PuCurveExtensionService::class)->extendOfficial($emission->fresh());

    expect($resumed->action)->toBe(PuCurveExtensionService::ACTION_EXTENDED)
        ->and(Fx::lastDate($official->fresh()))->toBeGreaterThan($lastDate);
});

it('never turns "no working version" into a full generation request for a non-activated emission', function () {
    Queue::fake();
    $emission = p7gEmission();

    app()->call([new ExtendPuDailyCurveJob($emission->id), 'handle']);

    Queue::assertNotPushed(GeneratePuDailyCurveJob::class);
    expect(app(PuCurveExtensionService::class)->extendWorking($emission)->action)->toBe(PuCurveExtensionService::ACTION_OPERATIONS_NOT_ACTIVE);
});

it('refuses homologation of a suspended emission inside the governance transaction, anchoring and refreshing nothing', function () {
    $emission = p7gEmission();
    Activation::activate($emission->id);
    $version = Fx::generate($emission);
    Activation::suspend($emission->id);
    $before = p7gCounts();

    expect(fn () => app(HomologatePuCurve::class)->handle($emission->fresh(), $version->calculation_version, User::factory()->create()->id, 'Conferida.'))
        ->toThrow(PuOperationalActivationException::class, PuOperationalActivationException::REASON_SUSPENDED)
        ->and($version->fresh()->status)->toBe(PuCurveStatus::Generated)
        ->and($version->fresh()->homologated_at)->toBeNull()
        ->and(p7gCounts())->toBe($before);
});

it('refuses a real settlement before any activation and while operations are disabled, but keeps it recordable after a suspension', function () {
    ['emission' => $emission] = p7gActivatedOfficial();
    $obligation = Fx::obligation($emission, PuObligationType::ScheduledPayment, '2026-03-09');
    $neverActivated = p7gEmission();

    $refusedBeforeActivation = app(PuSettlementService::class)->record(new PuSettlementData(
        emissionId: (int) $neverActivated->id,
        settlementDate: '2026-03-09',
        amount: '10.00',
        source: PuSettlementSource::B3,
        obligationType: PuObligationType::ScheduledPayment,
        contractualDate: '2026-03-09',
        sequence: 1,
        externalReference: 'B3-NEVER',
    ), Fx::integration());

    config([PuOperationsConfig::ENABLED_KEY => false]);
    $refusedWhileDisabled = Fx::settle($obligation, Fx::expectedTotal($obligation), reference: 'B3-OFF');

    config([PuOperationsConfig::ENABLED_KEY => true]);
    Activation::suspend($emission->id);
    $recordedWhileSuspended = Fx::settle($obligation, Fx::expectedTotal($obligation), reference: 'B3-SUSPENDED');

    expect($refusedBeforeActivation->outcome)->toBe(PuSettlementOutcome::Rejected)
        ->and($refusedBeforeActivation->reason)->toContain(PuOperationalActivationException::REASON_NOT_ACTIVATED)
        ->and($refusedWhileDisabled->outcome)->toBe(PuSettlementOutcome::Rejected)
        ->and($refusedWhileDisabled->reason)->toContain(PuOperationalActivationException::REASON_GLOBALLY_DISABLED)
        ->and($recordedWhileSuspended->outcome)->not->toBe(PuSettlementOutcome::Rejected)
        ->and(EmissionPuSettlement::query()->count())->toBe(1)
        ->and(EmissionPuSettlement::query()->sole()->external_reference)->toBe('B3-SUSPENDED');
});

it('shows a configured but non-activated emission as not activated, never as a missing or stale official curve', function () {
    $emission = p7gEmission();

    $health = collect(app(PuOperationalHealthService::class)->snapshot()->emissions)->firstWhere('emissionId', $emission->id);
    $types = collect($health->conditions)->map(fn ($condition) => $condition->type)->all();

    expect($health->eligibility)->toBe(PuOperationalEligibility::NotActivated)
        ->and($types)->toContain(PuOperationalConditionType::OperationsNotActivated)
        ->and($types)->not->toContain(PuOperationalConditionType::NoOfficialCurve)
        ->and(collect($health->conditions)->firstWhere('type', PuOperationalConditionType::OperationsNotActivated)->severity)->toBe(PuOperationalSeverity::Info);
});

it('flags a suspended official curve as needing attention without raising stale or failed-extension alerts, while financial facts keep alerting', function () {
    ['emission' => $emission] = p7gActivatedOfficial();
    Fx::publish('2026-03-16', '2026-03-27');
    Activation::suspend($emission->id);

    $health = collect(app(PuOperationalHealthService::class)->snapshot()->emissions)->firstWhere('emissionId', $emission->id);
    $suspended = collect($health->conditions)->firstWhere('type', PuOperationalConditionType::OperationsSuspended);
    $curveAlerts = collect($health->conditions)
        ->filter(fn ($condition): bool => in_array($condition->type, [
            PuOperationalConditionType::OfficialCurveStale,
            PuOperationalConditionType::OfficialCurveMissingIndex,
            PuOperationalConditionType::OfficialExtensionFailed,
            PuOperationalConditionType::NoOfficialCurve,
        ], true) && $condition->severity->atLeast(PuOperationalSeverity::Warning))
        ->map(fn ($condition) => $condition->type->value)
        ->values()
        ->all();

    expect($health->eligibility)->toBe(PuOperationalEligibility::Suspended)
        ->and($suspended)->not->toBeNull()
        ->and($suspended->severity)->toBe(PuOperationalSeverity::Warning)
        ->and($curveAlerts)->toBe([])
        // Suspender não esconde fato financeiro: a obrigação vencida sem liquidação continua alertando.
        ->and(collect($health->conditions)->pluck('type')->all())->toContain(PuOperationalConditionType::ObligationsUnsettledPastDue);
});

it('reports the environment switch in the monitor when operations are disabled, even for an activated emission', function () {
    $emission = p7gEmission();
    Activation::activate($emission->id);
    config([PuOperationsConfig::ENABLED_KEY => false]);

    $health = collect(app(PuOperationalHealthService::class)->snapshot()->emissions)->firstWhere('emissionId', $emission->id);
    $condition = collect($health->conditions)->firstWhere('type', PuOperationalConditionType::OperationsNotActivated);

    expect($health->eligibility)->toBe(PuOperationalEligibility::NotActivated)
        ->and($condition->reason)->toContain('PU_OPERATIONS_ENABLED');
});
