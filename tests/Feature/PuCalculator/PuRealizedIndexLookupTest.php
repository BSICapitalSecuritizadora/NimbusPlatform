<?php

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Services\BusinessDayCalendarService;
use App\Domain\PuCalculator\Services\IndexRateService;
use App\Domain\PuCalculator\Services\PuIndexRateRequirementResolver;
use App\Models\BusinessCalendarDate;
use App\Models\EmissionPuParameter;
use App\Models\IndexRate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Fase 3 -- a busca do CDI REALIZADO.
 *
 * A data de observação sai da regra contratual e do calendário, nunca dos dados:
 * a resposta é a observação realizada exatamente nessa data, ou nada. Os casos
 * de "previous available" legítimo (o divulgador não publica num dia que o
 * contrato conta como útil) continuam funcionando; repetir a última taxa
 * conhecida numa data cuja taxa ainda não existe deixou de existir.
 */
uses(RefreshDatabase::class);

/**
 * @param  list<string>  $holidays
 */
function p3lSeedCalendar(string $calendarCode, string $from, string $to, array $holidays = []): void
{
    for ($date = CarbonImmutable::parse($from); $date->lte(CarbonImmutable::parse($to)); $date = $date->addDay()) {
        BusinessCalendarDate::query()->create([
            'calendar_code' => $calendarCode,
            'calendar_date' => $date->toDateString(),
            'is_business_day' => ! $date->isWeekend() && ! in_array($date->toDateString(), $holidays, true),
            'description' => in_array($date->toDateString(), $holidays, true) ? 'Feriado de teste' : null,
        ]);
    }

    app(BusinessDayCalendarService::class)->flushCache();
}

function p3lCdi(string $date, string $value = '14.90000000', bool $projected = false): IndexRate
{
    return IndexRate::factory()->create([
        'indexer' => PuIndexer::Cdi->value,
        'rate_date' => $date,
        'rate_value' => $value,
        'source' => $projected ? 'scenario' : 'bcb_sgs',
        'source_reference' => $projected ? 'scenario:flat' : 'bcb_sgs:4389',
        'is_projected' => $projected,
    ]);
}

function p3lParameter(
    PuIndexRateLookupMode $mode,
    int $lag = -1,
    string $calendarCode = 'B3',
    ?string $rateCalendarCode = null,
): EmissionPuParameter {
    return EmissionPuParameter::factory()->create([
        'indexer' => PuIndexer::Cdi->value,
        'calendar_code' => $calendarCode,
        'index_rate_calendar_code' => $rateCalendarCode,
        'index_rate_lookup_mode' => $mode->value,
        'index_rate_lag_business_days' => $lag,
    ]);
}

function p3lResolve(EmissionPuParameter $parameter, string $curveDate)
{
    return app(PuIndexRateRequirementResolver::class)->resolve($parameter, CarbonImmutable::parse($curveDate));
}

it('does not hand the last known CDI to a later business day whose rate is not published yet', function () {
    p3lSeedCalendar('B3', '2026-08-03', '2026-12-31');
    p3lCdi('2026-08-06', '14.90000000');
    p3lCdi('2026-08-07', '15.10000000');
    $parameter = p3lParameter(PuIndexRateLookupMode::PreviousAvailableBusinessDay);

    $nextMonday = p3lResolve($parameter, '2026-08-10');
    $monthsAhead = p3lResolve($parameter, '2026-12-15');

    expect($nextMonday->lookupDate?->toDateString())->toBe('2026-08-10')
        ->and($nextMonday->rate)->toBeNull()
        ->and($nextMonday->isAwaitingPublication())->toBeTrue()
        ->and($nextMonday->isMissingHistoricalObservation())->toBeFalse()
        ->and($monthsAhead->rate)->toBeNull()
        ->and($monthsAhead->isAwaitingPublication())->toBeTrue();
});

it('still returns the historical observation of the previous publication day when the publisher did not publish on a contractual business day', function () {
    // O contrato acumula em 2026-09-07 (calendário B3 só de fins de semana), mas o
    // divulgador do índice não publica nesse feriado: a regra pede a observação do
    // último dia útil de DIVULGAÇÃO, 2026-09-04, que é histórica e existe.
    p3lSeedCalendar('B3', '2026-08-31', '2026-09-11');
    p3lSeedCalendar('HML_PHASE3_PUBLISHER', '2026-08-31', '2026-09-11', holidays: ['2026-09-07']);
    p3lCdi('2026-09-04', '14.65000000');
    p3lCdi('2026-09-08', '14.70000000');
    $parameter = p3lParameter(
        PuIndexRateLookupMode::PreviousAvailableBusinessDay,
        rateCalendarCode: 'HML_PHASE3_PUBLISHER',
    );

    $holidayForPublisher = p3lResolve($parameter, '2026-09-07');
    $nextDay = p3lResolve($parameter, '2026-09-08');

    expect($holidayForPublisher->isRequiredForCalculation())->toBeTrue()
        ->and($holidayForPublisher->lookupDate?->toDateString())->toBe('2026-09-04')
        ->and($holidayForPublisher->rate?->date->toDateString())->toBe('2026-09-04')
        ->and($holidayForPublisher->rate?->value)->toBe('14.65000000')
        ->and($nextDay->rate?->date->toDateString())->toBe('2026-09-08');
});

it('bounds the previous-available lookback by the publication calendar instead of searching the data backwards', function () {
    p3lSeedCalendar('B3', '2026-08-03', '2026-08-14');
    p3lCdi('2026-08-04');
    p3lCdi('2026-08-12');
    $parameter = p3lParameter(PuIndexRateLookupMode::PreviousAvailableBusinessDay);

    // 2026-08-06 é dia útil de divulgação, dentro do histórico já divulgado, e não
    // tem taxa: é buraco -- nunca a taxa de 04/08.
    $hole = p3lResolve($parameter, '2026-08-06');

    expect($hole->lookupDate?->toDateString())->toBe('2026-08-06')
        ->and($hole->rate)->toBeNull()
        ->and($hole->isMissingHistoricalObservation())->toBeTrue()
        ->and($hole->isAwaitingPublication())->toBeFalse();
});

dataset('p3l_calendar_cases', [
    'sabado nao exige taxa' => [PuIndexRateLookupMode::PreviousAvailableBusinessDay, 0, '2026-09-05', [], null, false],
    'feriado do contrato nao exige taxa' => [PuIndexRateLookupMode::PreviousAvailableBusinessDay, 0, '2026-09-07', ['2026-09-07'], null, false],
    'primeiro dia util depois do feriado usa a propria taxa' => [PuIndexRateLookupMode::PreviousAvailableBusinessDay, 0, '2026-09-08', ['2026-09-07'], '2026-09-08', true],
    'lag -1 na segunda usa a sexta' => [PuIndexRateLookupMode::BusinessDayLagExact, -1, '2026-09-14', [], '2026-09-11', true],
    'lag -1 depois do feriado pula o feriado' => [PuIndexRateLookupMode::BusinessDayLagExact, -1, '2026-09-08', ['2026-09-07'], '2026-09-04', true],
    'lag -2 atravessa fim de semana e feriado' => [PuIndexRateLookupMode::BusinessDayLagExact, -2, '2026-09-09', ['2026-09-07'], '2026-09-04', true],
]);

it('derives the required observation date from the actual business calendar and the configured lag', function (
    PuIndexRateLookupMode $mode,
    int $lag,
    string $curveDate,
    array $holidays,
    ?string $expectedLookupDate,
    bool $required,
) {
    p3lSeedCalendar('B3', '2026-08-31', '2026-09-18', $holidays);

    foreach (['2026-09-03', '2026-09-04', '2026-09-08', '2026-09-09', '2026-09-10', '2026-09-11', '2026-09-14'] as $published) {
        if (! in_array($published, $holidays, true)) {
            p3lCdi($published);
        }
    }

    $requirement = p3lResolve(p3lParameter($mode, $lag), $curveDate);

    expect($requirement->isRequiredForCalculation())->toBe($required);

    if ($required) {
        expect($requirement->lookupDate?->toDateString())->toBe($expectedLookupDate)
            ->and($requirement->rate?->date->toDateString())->toBe($expectedLookupDate);
    }
})->with('p3l_calendar_cases');

it('tells a missing historical observation apart from one not published yet under the lag rule', function () {
    p3lSeedCalendar('B3', '2026-09-01', '2026-09-30');
    p3lCdi('2026-09-14');
    p3lCdi('2026-09-16');
    $parameter = p3lParameter(PuIndexRateLookupMode::BusinessDayLagExact, -1);

    // 16/09 exige 15/09: buraco dentro do histórico (já existe 16/09).
    $hole = p3lResolve($parameter, '2026-09-16');
    // 18/09 exige 17/09: além da última observação, ainda não divulgado.
    $pending = p3lResolve($parameter, '2026-09-18');

    expect($hole->isMissingHistoricalObservation())->toBeTrue()
        ->and($pending->isAwaitingPublication())->toBeTrue();

    // A observação chega depois: a mesma data passa a ser realizada.
    p3lCdi('2026-09-17', '14.95000000');
    $published = p3lResolve($parameter, '2026-09-18');

    expect($published->rate?->date->toDateString())->toBe('2026-09-17')
        ->and($published->rate?->value)->toBe('14.95000000')
        ->and($published->lacksRealizedObservation())->toBeFalse();
});

dataset('p3l_lookup_modes', [
    'previous available' => [PuIndexRateLookupMode::PreviousAvailableBusinessDay, 0, '2026-10-01', '2026-10-01'],
    'business day lag' => [PuIndexRateLookupMode::BusinessDayLagExact, -1, '2026-10-02', '2026-10-01'],
    'previous calendar day' => [PuIndexRateLookupMode::PreviousCalendarDayExact, 0, '2026-10-02', '2026-10-01'],
]);

it('never answers a realized lookup with a projected CDI row', function (
    PuIndexRateLookupMode $mode,
    int $lag,
    string $curveDate,
    string $observationDate,
) {
    p3lSeedCalendar('B3', '2026-09-28', '2026-10-09');
    p3lCdi('2026-09-30');
    p3lCdi($observationDate, '13.00000000', projected: true);

    $requirement = p3lResolve(p3lParameter($mode, $lag), $curveDate);

    expect($requirement->lookupDate?->toDateString())->toBe($observationDate)
        ->and($requirement->rate)->toBeNull()
        ->and($requirement->lacksRealizedObservation())->toBeTrue()
        // A linha projetada não empurra a fronteira do que já foi divulgado.
        ->and($requirement->latestRealizedRateDate?->toDateString())->toBe('2026-09-30')
        ->and(app(IndexRateService::class)->realizedRateForDate(PuIndexer::Cdi, CarbonImmutable::parse($observationDate)))->toBeNull()
        ->and(app(IndexRateService::class)->exactRateForDate(PuIndexer::Cdi, CarbonImmutable::parse($observationDate))?->isProjected)->toBeTrue();
})->with('p3l_lookup_modes');
