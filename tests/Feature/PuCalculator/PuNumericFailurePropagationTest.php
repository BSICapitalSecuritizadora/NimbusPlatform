<?php

use App\Actions\Emissions\GeneratePuDailyCurve;
use App\Domain\PuCalculator\DTOs\PuSimulationInput;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Enums\PuSimulationState;
use App\Domain\PuCalculator\Exceptions\PuNumericConvergenceException;
use App\Domain\PuCalculator\Exceptions\PuRateDomainException;
use App\Domain\PuCalculator\Services\IndexRateLookupService;
use App\Domain\PuCalculator\Services\IndexRateService;
use App\Domain\PuCalculator\Services\PuCurveExtensionService;
use App\Domain\PuCalculator\Services\PuCurveGeneratorService;
use App\Domain\PuCalculator\Services\PuPersistedCurveChecksumService;
use App\Domain\PuCalculator\Services\PuSimulationService;
use App\Jobs\ExtendPuDailyCurveJob;
use App\Jobs\GeneratePuDailyCurveJob;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\IndexRate;
use App\Models\Payment;
use App\Models\PuHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Pu\PuSimulationFixture;

/**
 * Propagação da falha numérica da engine do PU (Fase 1, P0-04).
 *
 * `DailyFactorCalculator` lança `PuNumericConvergenceException` quando o Newton
 * limitado não certifica a raiz. Aqui a falha é provocada por DADO, nunca por dublê
 * da engine, e percorre os caminhos reais: gerador, ação de geração, job, simulação
 * e extensão diária. Nenhum deles pode transformar a falha em curva parcial, PU
 * corrente, pagamento ou simulação "calculada".
 *
 * Cenário: CDI com defasagem exata de 1 dia útil, curva de 02/03/2026 (segunda) a
 * 06/03/2026. O CDI de 05/03 é o gatilho, e só a linha de 06/03 o consome; as
 * linhas de 02/03 a 05/03 são calculadas normalmente antes da falha. Os gatilhos
 * ficam longe dos extremos medidos do Newton: 9.999,99999999% a.a. é o maior valor
 * das colunas de taxa dos parâmetros e -99,99999999% a.a. é a menor base positiva
 * que uma taxa de 8 casas produz (10^-10).
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    // Nenhum caminho daqui consulta fonte externa de taxa.
    Http::preventStrayRequests();
});

function numericFailureEmission(string $curveEndDate = '2026-03-06', string $failingCdi = '9999.99999999'): Emission
{
    $emission = Emission::factory()->active()->create([
        'type' => 'CRI',
        'current_pu' => '1234.567890',
    ]);
    $emission->integralizationHistories()->create([
        'date' => '2026-03-02',
        'quantity' => '100.0000',
        'unit_value' => '1000.00000000',
        'financial_value' => '100000.00',
        'investor_fund' => 'Head Invest',
    ]);
    $emission->puParameter()->create([
        'curve_start_date' => '2026-03-02',
        'curve_end_date' => $curveEndDate,
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '6.00000000',
        'indexer' => PuIndexer::Cdi->value,
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'index_rate_lookup_mode' => PuIndexRateLookupMode::BusinessDayLagExact->value,
        'index_rate_lag_business_days' => -1,
        'legacy_projection_enabled' => true,
    ]);

    publishNumericFailureCdi('2026-02-27', '2026-03-04', '14.90000000');
    publishNumericFailureCdi('2026-03-05', '2026-03-05', $failingCdi);

    return $emission->fresh();
}

function publishNumericFailureCdi(string $from, string $to, string $rateValue): void
{
    for ($date = CarbonImmutable::parse($from); $date->lte(CarbonImmutable::parse($to)); $date = $date->addDay()) {
        if ($date->isWeekend()) {
            continue;
        }

        IndexRate::factory()->create([
            'indexer' => PuIndexer::Cdi->value,
            'rate_date' => $date->toDateString(),
            'rate_value' => $rateValue,
            'source' => 'testing',
            'source_reference' => 'numeric-failure',
        ]);
    }

    // `IndexRateService` é singleton com cache em memória.
    app(IndexRateService::class)->flushCache();
    app(IndexRateLookupService::class)->flushCache();
}

/**
 * Tudo o que uma geração malsucedida não pode deixar para trás.
 *
 * @return array<string, int|string|null>
 */
function numericFailureFootprint(Emission $emission): array
{
    return [
        'versions' => EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->count(),
        'daily_curves' => EmissionPuDailyCurve::query()->where('emission_id', $emission->id)->count(),
        'pu_histories' => PuHistory::query()->where('emission_id', $emission->id)->count(),
        'payments' => Payment::query()->where('emission_id', $emission->id)->count(),
        'current_pu' => Emission::query()->whereKey($emission->id)->value('current_pu'),
    ];
}

// ---------------------------------------------------------------------------
// Geração
// ---------------------------------------------------------------------------

it('stops the official engine instead of returning a curve with an uncertified factor', function () {
    $emission = numericFailureEmission();

    expect(fn () => app(PuCurveGeneratorService::class)->handle($emission))
        ->toThrow(PuNumericConvergenceException::class, 'não convergiu');

    // Controle: a mesma emissão até a véspera do gatilho calcula normalmente.
    $emission->puParameter->forceFill(['curve_end_date' => '2026-03-05'])->save();

    expect(app(PuCurveGeneratorService::class)->handle($emission->fresh())->rows)->toHaveCount(4);
});

it('persists nothing when the generation action hits a numerical failure', function () {
    $emission = numericFailureEmission();
    $before = numericFailureFootprint($emission);

    expect(fn () => app(GeneratePuDailyCurve::class)->handle($emission))
        ->toThrow(PuNumericConvergenceException::class, 'não convergiu');

    expect(numericFailureFootprint($emission))->toBe($before)
        ->and($before['versions'])->toBe(0)
        ->and($before['daily_curves'])->toBe(0);
});

// ---------------------------------------------------------------------------
// Job de geração
// ---------------------------------------------------------------------------

it('marks the version as error, audits the failure and rethrows without persisting rows', function (string $failingCdi, string $exceptionClass, string $reason) {
    $user = User::factory()->create();
    $emission = numericFailureEmission(failingCdi: $failingCdi);
    $before = numericFailureFootprint($emission);
    $job = new GeneratePuDailyCurveJob($emission->id, $user->id);

    expect(fn () => app()->call([$job, 'handle']))->toThrow($exceptionClass, $reason);

    $versions = EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->get();
    $failures = Activity::query()
        ->where('log_name', 'pu-calculation')
        ->where('description', 'pu_curve_generation_failed')
        ->where('subject_id', $emission->id)
        ->get();
    $footprint = numericFailureFootprint($emission);
    $status = Cache::get("pu_curve_generation_{$emission->id}_status");

    expect($versions)->toHaveCount(1)
        ->and($versions->first()->status)->toBe(PuCurveStatus::Error)
        ->and($versions->first()->error_message)->toContain($reason)
        ->and($versions->first()->generated_at)->toBeNull()
        ->and($footprint['daily_curves'])->toBe(0)
        ->and($footprint['pu_histories'])->toBe(0)
        ->and($footprint['payments'])->toBe(0)
        ->and($footprint['current_pu'])->toBe($before['current_pu'])
        ->and(bccomp((string) $footprint['current_pu'], '1234.567890', 6))->toBe(0)
        ->and($failures)->toHaveCount(1)
        ->and($failures->first()->properties['error_message'])->toContain($reason)
        ->and($failures->first()->causer_id)->toBe($user->id)
        ->and(Activity::query()->where('description', 'pu_curve_generated')->where('subject_id', $emission->id)->exists())->toBeFalse()
        ->and($status['status'])->toBe('failed')
        ->and($status['error'])->toContain($reason)
        // A trava foi liberada: a próxima geração não fica bloqueada pela que falhou.
        ->and(Cache::lock("pu_curve_generation_{$emission->id}_lock", 1)->get())->toBeTrue();
})->with([
    'CDI muito acima do alcance do Newton' => ['9999.99999999', PuNumericConvergenceException::class, 'não convergiu'],
    'CDI colado em -100% a.a.' => ['-99.99999999', PuNumericConvergenceException::class, 'não convergiu'],
    // Recusa de DOMÍNIO, antes da raiz: o job a trata exatamente como a numérica.
    'CDI de -100% a.a.' => ['-100.00000000', PuRateDomainException::class, 'acima de -100% a.a.'],
]);

it('keeps the previous version and the current PU intact when a regeneration fails', function () {
    $emission = numericFailureEmission(curveEndDate: '2026-03-05');
    app()->call([new GeneratePuDailyCurveJob($emission->id), 'handle']);

    $previous = EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->firstOrFail();
    $checksums = app(PuPersistedCurveChecksumService::class);
    $previousChecksum = $checksums->checksum($previous);
    $before = numericFailureFootprint($emission);

    $emission->puParameter->forceFill(['curve_end_date' => '2026-03-06'])->save();

    expect(fn () => app()->call([new GeneratePuDailyCurveJob($emission->id), 'handle']))
        ->toThrow(PuNumericConvergenceException::class, 'não convergiu');

    $failed = EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->whereKeyNot($previous->id)->firstOrFail();
    $after = numericFailureFootprint($emission);

    expect($previous->fresh()->status)->toBe(PuCurveStatus::Generated)
        ->and($previous->dailyCurves()->count())->toBe(4)
        ->and($checksums->checksum($previous->fresh()))->toBe($previousChecksum)
        ->and($failed->status)->toBe(PuCurveStatus::Error)
        ->and($failed->dailyCurves()->count())->toBe(0)
        ->and($after['daily_curves'])->toBe($before['daily_curves'])
        ->and($after['pu_histories'])->toBe($before['pu_histories'])
        ->and($after['payments'])->toBe($before['payments'])
        ->and($after['current_pu'])->toBe($before['current_pu'])
        // A geração anterior, bem-sucedida, já tinha substituído o PU de partida.
        ->and(bccomp((string) $before['current_pu'], '1234.567890', 6))->not->toBe(0);
});

// ---------------------------------------------------------------------------
// Simulação
// ---------------------------------------------------------------------------

it('reports a numerical failure as a failed simulation without any PU row', function () {
    $emission = PuSimulationFixture::bareEmission();
    $simulate = fn (array $overrides) => app(PuSimulationService::class)->simulate($emission, new PuSimulationInput(
        firstIntegralizationDate: PuSimulationFixture::integralizationDate(),
        simulationEndDate: PuSimulationFixture::windowEndDate(),
        overrides: [...PuSimulationFixture::manualOverrides(), ...$overrides],
    ));
    $before = PuSimulationFixture::counts();

    $failed = $simulate(['spread_rate' => '9999.99999999']);
    // Controle: o mesmo cenário com o spread do fixture calcula.
    $control = $simulate([]);

    expect($failed->state)->toBe(PuSimulationState::CalculationFailed)
        ->and($failed->calculated())->toBeFalse()
        ->and($failed->rows)->toBe([])
        ->and($failed->selectedRow)->toBeNull()
        ->and($failed->selectedUnitValue())->toBeNull()
        ->and($failed->reason)->toContain('não convergiu')
        ->and($control->state)->toBe(PuSimulationState::Calculated)
        ->and($control->rows)->not->toBe([])
        ->and(PuSimulationFixture::counts())->toBe($before);
});

// ---------------------------------------------------------------------------
// Extensão diária
// ---------------------------------------------------------------------------

/**
 * Curva vigente gravada até 05/03 (CDI válido até 04/03), com um CDI válido em
 * 05/03 e o gatilho em 06/03. O recálculo da extensão produziria primeiro a linha
 * válida de 06/03 e só falharia na de 09/03: se a extensão anexasse aos poucos,
 * 06/03 apareceria gravado.
 *
 * @return array{emission:Emission, version:EmissionPuCurveVersion, checksum:string, footprint:array<string, int|string|null>}
 */
function numericFailureExtensionScenario(): array
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
    publishNumericFailureCdi('2026-02-27', '2026-03-04', '14.90000000');
    app(GeneratePuDailyCurve::class)->handle($emission->fresh());

    $emission = $emission->fresh();
    $version = $emission->currentPuCurveVersion();

    publishNumericFailureCdi('2026-03-05', '2026-03-05', '14.90000000');
    publishNumericFailureCdi('2026-03-06', '2026-03-06', '9999.99999999');

    return [
        'emission' => $emission,
        'version' => $version,
        'checksum' => app(PuPersistedCurveChecksumService::class)->checksum($version),
        'footprint' => numericFailureFootprint($emission),
    ];
}

it('appends no partial tail when the extension hits a numerical failure', function () {
    $scenario = numericFailureExtensionScenario();
    $version = $scenario['version'];

    expect(CarbonImmutable::parse((string) $version->dailyCurves()->max('curve_date'))->toDateString())->toBe('2026-03-05');

    expect(fn () => app(PuCurveExtensionService::class)->extend($scenario['emission']->fresh()))
        ->toThrow(PuNumericConvergenceException::class, 'não convergiu');

    $version->refresh();

    expect(numericFailureFootprint($scenario['emission']))->toBe($scenario['footprint'])
        ->and(app(PuPersistedCurveChecksumService::class)->checksum($version))->toBe($scenario['checksum'])
        ->and($version->dailyCurves()->whereDate('curve_date', '2026-03-06')->exists())->toBeFalse()
        ->and($version->dailyCurves()->whereNotNull('extended_at')->exists())->toBeFalse()
        ->and($version->extended_rows_count)->toBe(0)
        ->and($version->last_extended_at)->toBeNull()
        ->and($version->status)->toBe(PuCurveStatus::Generated);

    // Controle: corrigido o dado, a mesma extensão anexa 06/03 sobre o trecho intacto.
    IndexRate::query()->where('source_reference', 'numeric-failure')->where('rate_value', '>', 100)->update(['rate_value' => '14.90000000']);
    app(IndexRateService::class)->flushCache();
    app(IndexRateLookupService::class)->flushCache();

    $recovered = app(PuCurveExtensionService::class)->extend($scenario['emission']->fresh());

    expect($recovered->action)->toBe(PuCurveExtensionService::ACTION_EXTENDED)
        ->and($recovered->fromDate)->toBe('2026-03-06')
        ->and($version->dailyCurves()->whereDate('curve_date', '2026-03-06')->exists())->toBeTrue();
});

it('lets the extension job fail loudly, release the lock and skip the full regeneration', function () {
    Queue::fake();
    $scenario = numericFailureExtensionScenario();
    $emissionId = $scenario['emission']->id;

    expect(fn () => app()->call([new ExtendPuDailyCurveJob($emissionId), 'handle']))
        ->toThrow(PuNumericConvergenceException::class, 'não convergiu');

    Queue::assertNotPushed(GeneratePuDailyCurveJob::class);

    expect(numericFailureFootprint($scenario['emission']))->toBe($scenario['footprint'])
        ->and(app(PuPersistedCurveChecksumService::class)->checksum($scenario['version']->fresh()))->toBe($scenario['checksum'])
        ->and(Cache::lock("pu_curve_generation_{$emissionId}_lock", 1)->get())->toBeTrue();
});
