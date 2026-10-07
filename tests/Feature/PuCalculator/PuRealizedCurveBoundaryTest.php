<?php

use App\Actions\Emissions\GeneratePuDailyCurve;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Services\BusinessDayCalendarService;
use App\Domain\PuCalculator\Services\PuCurveGenerationService;
use App\Domain\PuCalculator\Services\PuCurvePrerequisiteService;
use App\Domain\PuCalculator\Services\PuSimulationService;
use App\Models\BusinessCalendarDate;
use App\Models\Emission;
use App\Models\EmissionPuDailyCurve;
use App\Models\IndexRate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Fase 3 (P0-02) -- a curva realizada termina no último dia sustentado por CDI
 * divulgado.
 *
 * Antes, no modo `PreviousAvailableBusinessDay`, a geração ia até o vencimento
 * repetindo o último CDI conhecido e gravava tudo como realizado. Agora a curva
 * para no primeiro dia útil cuja observação ainda não existe, em qualquer modo, e
 * nenhuma linha projetada (nem a última taxa repetida, nem uma linha marcada como
 * projeção) sustenta um dia realizado.
 */
uses(RefreshDatabase::class);

function p3bSeedCalendar(string $from, string $to): void
{
    for ($date = CarbonImmutable::parse($from); $date->lte(CarbonImmutable::parse($to)); $date = $date->addDay()) {
        BusinessCalendarDate::query()->create([
            'calendar_code' => 'B3',
            'calendar_date' => $date->toDateString(),
            'is_business_day' => ! $date->isWeekend(),
            'description' => null,
        ]);
    }

    app(BusinessDayCalendarService::class)->flushCache();
}

function p3bPublishCdi(string $from, string $to, string $value = '14.90000000', bool $projected = false): void
{
    for ($date = CarbonImmutable::parse($from); $date->lte(CarbonImmutable::parse($to)); $date = $date->addDay()) {
        if ($date->isWeekend()) {
            continue;
        }

        IndexRate::factory()->create([
            'indexer' => PuIndexer::Cdi->value,
            'rate_date' => $date->toDateString(),
            'rate_value' => $value,
            'source' => $projected ? 'scenario' : 'bcb_sgs',
            'source_reference' => $projected ? 'scenario:flat' : 'bcb_sgs:4389',
            'is_projected' => $projected,
        ]);
    }
}

function p3bEmission(PuIndexRateLookupMode $mode, int $lag = 0): Emission
{
    $emission = Emission::factory()->active()->create(['type' => 'CRI']);
    $emission->integralizationHistories()->create([
        'date' => '2026-08-03',
        'quantity' => '100.0000',
        'unit_value' => '1000.00000000',
        'financial_value' => '100000.00',
        'investor_fund' => 'Head Invest',
    ]);
    $emission->puParameter()->create([
        'curve_start_date' => '2026-08-03',
        'curve_end_date' => '2027-02-26',
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '6.00000000',
        'indexer' => PuIndexer::Cdi->value,
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'index_rate_lookup_mode' => $mode->value,
        'index_rate_lag_business_days' => $lag,
        'legacy_projection_enabled' => false,
    ]);
    p3bSeedCalendar('2026-07-27', '2027-03-05');

    return $emission->fresh();
}

/**
 * @return list<string>
 */
function p3bPersistedDates(Emission $emission): array
{
    return EmissionPuDailyCurve::query()
        ->where('emission_id', $emission->id)
        ->orderBy('curve_date')
        ->pluck('curve_date')
        ->map(fn ($date): string => CarbonImmutable::parse((string) $date)->toDateString())
        ->all();
}

it('stops the generated curve at the last day backed by published CDI instead of repeating it to maturity', function () {
    $emission = p3bEmission(PuIndexRateLookupMode::PreviousAvailableBusinessDay);
    p3bPublishCdi('2026-08-03', '2026-08-21');

    $prerequisites = app(PuCurvePrerequisiteService::class)->handle($emission);
    app(GeneratePuDailyCurve::class)->handle($emission);
    $dates = p3bPersistedDates($emission);
    $businessRows = EmissionPuDailyCurve::query()
        ->where('emission_id', $emission->id)
        ->where('is_business_day', true)
        ->whereDate('curve_date', '>', '2026-08-03')
        ->get();

    expect($prerequisites->passes())->toBeTrue()
        ->and(collect($prerequisites->warningMessages())->contains(
            fn (string $message): bool => str_contains($message, 'aguardam a publicacao do CDI'),
        ))->toBeTrue()
        // Sábado e domingo depois do último CDI não acumulam e são realizados; a
        // segunda-feira 24/08 exige o CDI do próprio dia, que ainda não existe.
        ->and($dates[0])->toBe('2026-08-03')
        ->and(end($dates))->toBe('2026-08-23')
        ->and($dates)->toHaveCount(21)
        ->and($emission->puCurveVersions()->sole()->rows_count)->toBe(21)
        ->and(EmissionPuDailyCurve::query()->where('emission_id', $emission->id)->whereDate('curve_date', '>', '2026-08-23')->exists())->toBeFalse()
        ->and($businessRows->every(
            fn (EmissionPuDailyCurve $row): bool => CarbonImmutable::parse((string) $row->index_rate_date)->toDateString()
                === CarbonImmutable::parse((string) $row->curve_date)->toDateString(),
        ))->toBeTrue();
});

it('ends the lagged realized curve on the last day whose lagged observation exists', function () {
    $emission = p3bEmission(PuIndexRateLookupMode::BusinessDayLagExact, -1);
    p3bPublishCdi('2026-07-31', '2026-08-21');

    app(GeneratePuDailyCurve::class)->handle($emission);
    $dates = p3bPersistedDates($emission);
    $monday = EmissionPuDailyCurve::query()->where('emission_id', $emission->id)->whereDate('curve_date', '2026-08-24')->sole();

    expect(end($dates))->toBe('2026-08-24')
        ->and(CarbonImmutable::parse((string) $monday->index_rate_date)->toDateString())->toBe('2026-08-21');
});

it('keeps the previous-calendar-day engine from computing any day past the last published CDI', function () {
    $emission = p3bEmission(PuIndexRateLookupMode::PreviousCalendarDayExact);
    p3bPublishCdi('2026-08-03', '2026-08-21');

    // Sábado 22/08 usa o CDI da sexta. Domingo e segunda não exigem nada (sábado e
    // domingo não têm divulgação) e têm Fator DI 1 -- isso é a regra do contrato,
    // não projeção. Terça exige o CDI de segunda, além do último divulgado: a
    // curva realizada termina na segunda.
    app(GeneratePuDailyCurve::class)->handle($emission);
    $dates = p3bPersistedDates($emission);
    $rows = app(PuCurveGenerationService::class)->handle($emission->fresh())->rows;
    $byDate = collect($rows)->keyBy(fn ($row): string => $row->date->toDateString());

    expect(end($dates))->toBe('2026-08-24')
        ->and(end($rows)->date->toDateString())->toBe('2026-08-24')
        ->and($byDate['2026-08-22']->indexRateDate?->toDateString())->toBe('2026-08-21')
        ->and($byDate['2026-08-23']->indexRateDate)->toBeNull()
        ->and($byDate['2026-08-23']->factorDi)->toBe('1.0000000000000000')
        ->and($byDate['2026-08-24']->indexRateDate)->toBeNull()
        ->and($byDate['2026-08-24']->factorDi)->toBe('1.0000000000000000')
        ->and($byDate['2026-08-09']->factorDi)->toBe('1.0000000000000000');
});

it('does not let projected CDI rows extend the realized curve or its boundary', function () {
    $emission = p3bEmission(PuIndexRateLookupMode::PreviousAvailableBusinessDay);
    p3bPublishCdi('2026-08-03', '2026-08-21');
    p3bPublishCdi('2026-08-24', '2026-09-30', '15.25000000', projected: true);

    app(GeneratePuDailyCurve::class)->handle($emission);
    $dates = p3bPersistedDates($emission);

    expect(end($dates))->toBe('2026-08-23')
        ->and(EmissionPuDailyCurve::query()
            ->where('emission_id', $emission->id)
            ->where('index_rate_value', '15.25000000')
            ->exists())->toBeFalse();
});

it('reports the future CDI of a simulation as missing instead of fabricating it from the last published rate', function () {
    $emission = p3bEmission(PuIndexRateLookupMode::PreviousAvailableBusinessDay);
    p3bPublishCdi('2026-08-03', '2026-08-21');

    $plan = app(PuSimulationService::class)->ratePlan(
        $emission->puParameter,
        CarbonImmutable::parse('2026-08-03'),
        CarbonImmutable::parse('2026-09-04'),
    );

    expect($plan['missing_rate_dates'])->toContain('2026-08-24')
        ->and($plan['missing_rate_dates'])->toContain('2026-09-04')
        ->and($plan['missing_rate_dates'])->not->toContain('2026-08-21')
        // Cada data exigida é a do próprio dia útil: a de 21/08 não responde por nenhuma outra.
        ->and(collect($plan['required_rate_dates'])->filter(fn (string $date): bool => $date >= '2026-08-24')->count())
        ->toBe(count($plan['missing_rate_dates']));
});
