<?php

use App\Actions\Emissions\GeneratePuDailyCurve;
use App\Actions\Emissions\HomologatePuCurve;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Enums\PuOfficialCurveFreshness;
use App\Domain\PuCalculator\Services\BusinessDayCalendarService;
use App\Domain\PuCalculator\Services\EmissionPuReader;
use App\Domain\PuCalculator\Services\PuCurveExtensionService;
use App\Domain\PuCalculator\Services\PuOfficialCurveFreshnessService;
use App\Domain\PuCalculator\Services\PuOperationalMonitorService;
use App\Models\BusinessCalendarDate;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\IndexRate;
use App\Models\User;
use App\Services\Reports\EmissionMonthlyReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

/**
 * Fase 3 -- atualidade da curva oficial.
 *
 * A curva oficial não está atrasada só porque hoje é depois da última linha: o
 * CDI do dia útil D chega na manhã do dia útil seguinte, e a curva com defasagem
 * de um dia útil ainda precisa do CDI de D para calcular D+1. Os estados
 * separam o que é normal (índice ainda não divulgado), o que é dado faltando
 * (buraco ou divulgação atrasada), o que é processamento devendo (índice já
 * disponível e não incorporado) e o que é falha da extensão. Em nenhum deles o
 * último PU é apresentado como o PU de uma data posterior.
 *
 * Datas e horários em America/Sao_Paulo (UTC−3): a divulgação é esperada a partir
 * das 07:00 do dia útil seguinte (`pu_indexes.bcb.series.cdi.available_after`).
 */
uses(RefreshDatabase::class);

function p3fEmission(): Emission
{
    $emission = Emission::factory()->active()->create(['type' => 'CRI', 'integralized_quantity' => 100]);
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
        'legacy_projection_enabled' => false,
    ]);

    for ($date = CarbonImmutable::parse('2026-02-23'); $date->lte(CarbonImmutable::parse('2027-01-08')); $date = $date->addDay()) {
        BusinessCalendarDate::query()->create([
            'calendar_code' => 'B3',
            'calendar_date' => $date->toDateString(),
            'is_business_day' => ! $date->isWeekend(),
            'description' => null,
        ]);
    }

    app(BusinessDayCalendarService::class)->flushCache();

    return $emission->fresh();
}

/**
 * @param  list<string>  $except
 */
function p3fPublish(string $from, string $to, array $except = []): void
{
    for ($date = CarbonImmutable::parse($from); $date->lte(CarbonImmutable::parse($to)); $date = $date->addDay()) {
        if (! $date->isWeekend() && ! in_array($date->toDateString(), $except, true)) {
            IndexRate::factory()->create([
                'indexer' => PuIndexer::Cdi->value,
                'rate_date' => $date->toDateString(),
                'rate_value' => '14.90000000',
                'source' => 'bcb_sgs',
                'source_reference' => 'bcb_sgs:4389',
            ]);
        }
    }
}

/**
 * Curva homologada com o CDI de 27/02 a 13/03: realizada até segunda, 16/03.
 */
function p3fOfficialEmission(): Emission
{
    $emission = p3fEmission();
    p3fPublish('2026-02-27', '2026-03-13');
    $result = app(GeneratePuDailyCurve::class)->handle($emission);
    app(HomologatePuCurve::class)->handle($emission->fresh(), $result->calculationVersion, User::factory()->create()->id, 'Conferida.');

    return $emission->fresh();
}

function p3fAt(string $localDateTime): CarbonImmutable
{
    return CarbonImmutable::parse($localDateTime, 'America/Sao_Paulo');
}

function p3fStatus(Emission $emission, string $localDateTime)
{
    return app(PuOfficialCurveFreshnessService::class)->status($emission->fresh(), p3fAt($localDateTime));
}

it('treats the official curve as current while the next required CDI is not due yet', function () {
    $emission = p3fOfficialEmission();

    $monday = p3fStatus($emission, '2026-03-16 10:00');
    // Terça antes das 07:00 o CDI de segunda ainda não precisa estar no banco.
    $tuesdayDawn = p3fStatus($emission, '2026-03-17 06:00');

    expect($monday->freshness)->toBe(PuOfficialCurveFreshness::Current)
        ->and($monday->realizedThrough?->toDateString())->toBe('2026-03-16')
        ->and($monday->expectedRealizedThrough?->toDateString())->toBe('2026-03-16')
        ->and($monday->nextRequiredRateDate?->toDateString())->toBe('2026-03-16')
        ->and($monday->latestRealizedRateDate?->toDateString())->toBe('2026-03-13')
        ->and($monday->expectedLatestRateDate?->toDateString())->toBe('2026-03-13')
        ->and($tuesdayDawn->freshness)->toBe(PuOfficialCurveFreshness::Current);
});

it('flags the required CDI as missing once its publication is overdue', function () {
    $emission = p3fOfficialEmission();

    $status = p3fStatus($emission, '2026-03-18 10:00');

    expect($status->freshness)->toBe(PuOfficialCurveFreshness::MissingIndex)
        ->and($status->expectedLatestRateDate?->toDateString())->toBe('2026-03-17')
        ->and($status->nextRequiredRateDate?->toDateString())->toBe('2026-03-16')
        ->and($status->reason)->toContain('já deveria ter sido divulgada');
});

it('flags a hole in the published history as missing index even before it is overdue', function () {
    $emission = p3fOfficialEmission();
    p3fPublish('2026-03-16', '2026-03-18', except: ['2026-03-17']);

    $status = p3fStatus($emission, '2026-03-19 06:00');
    $extension = app(PuCurveExtensionService::class)->extendOfficial($emission->fresh());

    expect($status->freshness)->toBe(PuOfficialCurveFreshness::MissingIndex)
        ->and($status->expectedRealizedThrough?->toDateString())->toBe('2026-03-17')
        ->and($status->nextRequiredRateDate?->toDateString())->toBe('2026-03-17')
        ->and($status->reason)->toContain('dentro do histórico já divulgado')
        // A extensão é barrada pelo buraco e grava a falha; a causa reportada continua sendo o índice.
        ->and($extension->action)->toBe(PuCurveExtensionService::ACTION_PREREQUISITES_BLOCKED)
        ->and(p3fStatus($emission, '2026-03-19 06:00')->freshness)->toBe(PuOfficialCurveFreshness::MissingIndex);
});

it('detects a stale official curve and never presents its last PU as the PU of a later date', function () {
    $emission = p3fOfficialEmission();
    p3fPublish('2026-03-16', '2026-03-18');
    $reader = app(EmissionPuReader::class);

    $status = p3fStatus($emission, '2026-03-19 10:00');
    $lastKnown = $reader->readingOn($emission->fresh(), CarbonImmutable::parse('2026-03-19'));
    $strict = $reader->officialReadingAt($emission->fresh(), CarbonImmutable::parse('2026-03-19'), p3fAt('2026-03-19 10:00'));
    $covered = $reader->officialReadingAt($emission->fresh(), CarbonImmutable::parse('2026-03-16'), p3fAt('2026-03-19 10:00'));
    $report = app(EmissionMonthlyReportService::class)->build($emission->fresh(), CarbonImmutable::parse('2026-03-01'));

    expect($status->freshness)->toBe(PuOfficialCurveFreshness::Stale)
        ->and($status->realizedThrough?->toDateString())->toBe('2026-03-16')
        ->and($status->expectedRealizedThrough?->toDateString())->toBe('2026-03-19')
        ->and($lastKnown?->date->toDateString())->toBe('2026-03-16')
        ->and($lastKnown?->isCarriedForward())->toBeTrue()
        ->and($strict['reading'])->toBeNull()
        ->and($strict['status']->freshness)->toBe(PuOfficialCurveFreshness::Stale)
        ->and($covered['reading']?->date->toDateString())->toBe('2026-03-16')
        ->and($covered['reading']?->isCarriedForward())->toBeFalse()
        // O relatório de março mostra a posição na data do PU, não em 31/03.
        ->and($report['header']['debt_position'])->toBe('16/03/2026');

    app(PuCurveExtensionService::class)->extendOfficial($emission->fresh());

    expect(p3fStatus($emission, '2026-03-19 10:00')->freshness)->toBe(PuOfficialCurveFreshness::Current)
        ->and($reader->officialReadingAt($emission->fresh(), CarbonImmutable::parse('2026-03-19'), p3fAt('2026-03-19 10:00'))['reading']?->date->toDateString())
        ->toBe('2026-03-19');
});

it('reports an extension failure apart from missing index data', function () {
    $emission = p3fOfficialEmission();
    p3fPublish('2026-03-16', '2026-03-18');
    $emission->integralizationHistories()->delete();

    app(PuCurveExtensionService::class)->extendOfficial($emission->fresh());
    $status = p3fStatus($emission, '2026-03-19 10:00');
    $strict = app(EmissionPuReader::class)->officialReadingAt($emission->fresh(), CarbonImmutable::parse('2026-03-19'), p3fAt('2026-03-19 10:00'));

    expect($status->freshness)->toBe(PuOfficialCurveFreshness::ExtensionFailed)
        ->and($status->reason)->toContain('integralizacao')
        ->and($status->expectedRealizedThrough?->toDateString())->toBe('2026-03-19')
        ->and($strict['reading'])->toBeNull()
        ->and($strict['status']->freshness)->toBe(PuOfficialCurveFreshness::ExtensionFailed);
});

it('has no official reading without a homologated curve, even with a generated one', function () {
    $emission = p3fEmission();
    p3fPublish('2026-02-27', '2026-03-13');
    app(GeneratePuDailyCurve::class)->handle($emission);

    $status = p3fStatus($emission, '2026-03-16 10:00');
    $strict = app(EmissionPuReader::class)->officialReadingAt($emission->fresh(), CarbonImmutable::parse('2026-03-10'), p3fAt('2026-03-16 10:00'));

    expect(EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->count())->toBe(1)
        ->and($status->freshness)->toBe(PuOfficialCurveFreshness::NoOfficialCurve)
        ->and($strict['reading'])->toBeNull();
});

it('reports a fully realized official curve as complete', function () {
    $emission = p3fEmission();
    $emission->puParameter->forceFill(['curve_end_date' => '2026-03-13'])->save();
    p3fPublish('2026-02-27', '2026-03-13');
    $result = app(GeneratePuDailyCurve::class)->handle($emission->fresh());
    app(HomologatePuCurve::class)->handle($emission->fresh(), $result->calculationVersion, User::factory()->create()->id, 'Conferida.');

    expect(p3fStatus($emission, '2026-06-01 10:00')->freshness)->toBe(PuOfficialCurveFreshness::Complete);
});

it('raises an operational alert only when the official curve is stopped by missing CDI', function () {
    $emission = p3fOfficialEmission();
    p3fPublish('2026-03-16', '2026-03-18');

    // Atrasada mas com o índice disponível: a rotina resolve sozinha, sem alerta.
    $this->travelTo(p3fAt('2026-03-19 10:00'));
    $stale = app(PuOperationalMonitorService::class);

    expect($stale->officialCurvesMissingIndexEmissionIds())->toBe([]);

    Cache::forget('pu_monitor_official_missing_index_ids');
    $this->travelTo(p3fAt('2026-03-25 10:00'));
    app(PuCurveExtensionService::class)->extendOfficial($emission->fresh());

    expect(app(PuOperationalMonitorService::class)->officialCurvesMissingIndexEmissionIds())->toBe([$emission->id])
        ->and(app(PuOperationalMonitorService::class)->criticalSummary())
        ->toContain('1 curva(s) oficial(is) parada(s) por CDI exigido ausente ou com divulgacao atrasada.');
});
