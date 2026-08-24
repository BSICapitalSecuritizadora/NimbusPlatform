<?php

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Services\BusinessDayCalendarService;
use App\Domain\PuCalculator\Services\IndexRateService;
use App\Domain\PuCalculator\Services\PuCurveGenerationService;
use App\Domain\PuCalculator\Services\PuCurvePrerequisiteService;
use App\Domain\PuCalculator\Services\PuIndexCoverageService;
use App\Domain\PuCalculator\Services\PuIndexRateRequirementResolver;
use App\Models\BusinessCalendarDate;
use App\Models\Emission;
use App\Models\EmissionPuParameter;
use App\Models\IndexRate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function phase2b3Parameter(
    PuIndexRateLookupMode $lookupMode,
    string $calendarCode = 'B3',
    int $businessDayLag = -1,
): EmissionPuParameter {
    return EmissionPuParameter::factory()->create([
        'indexer' => PuIndexer::Cdi->value,
        'calendar_code' => $calendarCode,
        'index_rate_lookup_mode' => $lookupMode->value,
        'index_rate_lag_business_days' => $businessDayLag,
    ]);
}

function phase2b3Emission(
    string $startDate,
    string $endDate,
    PuIndexRateLookupMode $lookupMode,
    string $calendarCode = 'B3',
    int $businessDayLag = -1,
): Emission {
    $emission = Emission::factory()->active()->create(['type' => 'CRI']);

    $emission->integralizationHistories()->create([
        'date' => $startDate,
        'quantity' => '10.0000',
        'unit_value' => '1000.00000000',
        'financial_value' => '10000.00',
        'investor_fund' => 'Head Invest',
    ]);

    $emission->puParameter()->create([
        'curve_start_date' => $startDate,
        'curve_end_date' => $endDate,
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '6.50000000',
        'indexer' => PuIndexer::Cdi->value,
        'business_day_basis' => 252,
        'calendar_code' => $calendarCode,
        'index_rate_lookup_mode' => $lookupMode->value,
        'index_rate_lag_business_days' => $businessDayLag,
        'legacy_projection_enabled' => false,
    ]);

    return $emission;
}

/**
 * @param  list<string>  $holidays
 */
function phase2b3SeedCalendar(
    string $startDate,
    string $endDate,
    string $calendarCode = 'B3',
    array $holidays = [],
): void {
    for ($date = CarbonImmutable::parse($startDate); $date->lte(CarbonImmutable::parse($endDate)); $date = $date->addDay()) {
        BusinessCalendarDate::query()->create([
            'calendar_code' => $calendarCode,
            'calendar_date' => $date->toDateString(),
            'is_business_day' => ! $date->isWeekend() && ! in_array($date->toDateString(), $holidays, true),
            'description' => in_array($date->toDateString(), $holidays, true) ? 'Feriado de teste' : null,
        ]);
    }

    app(BusinessDayCalendarService::class)->flushCache();
}

function phase2b3SeedCdi(string $rateDate, bool $projected = false): void
{
    IndexRate::factory()->create([
        'indexer' => PuIndexer::Cdi->value,
        'rate_date' => $rateDate,
        'rate_value' => '14.90000000',
        'source' => 'testing',
        'source_reference' => $projected ? 'forward_projection' : 'phase-2b3',
    ]);

    app(IndexRateService::class)->flushCache();
}

dataset('phase2b3_previous_calendar_day_cases', [
    'segunda-feira consulta domingo' => ['2026-08-10', '2026-08-09', []],
    'domingo consulta sabado' => ['2026-08-09', '2026-08-08', []],
    'dia seguinte ao feriado consulta o feriado' => ['2026-09-08', '2026-09-07', ['2026-09-07']],
    'virada de mes consulta o ultimo dia do mes anterior' => ['2026-09-01', '2026-08-31', []],
    'virada de ano consulta 31 de dezembro' => ['2027-01-01', '2026-12-31', []],
]);

it('keeps engine prerequisite and coverage on exact D-1 calendar across boundaries', function (
    string $curveDate,
    string $requiredRateDate,
    array $holidays,
) {
    $startDate = CarbonImmutable::parse($curveDate)->subDay()->toDateString();
    $emission = phase2b3Emission(
        $startDate,
        $curveDate,
        PuIndexRateLookupMode::PreviousCalendarDayExact,
    );
    phase2b3SeedCalendar($startDate, $curveDate, holidays: $holidays);
    phase2b3SeedCdi($requiredRateDate);

    $parameter = $emission->puParameter;
    $requirement = app(PuIndexRateRequirementResolver::class)->resolve(
        $parameter,
        CarbonImmutable::parse($curveDate),
    );
    $prerequisite = app(PuCurvePrerequisiteService::class)->handle($emission->fresh());
    $coverage = app(PuIndexCoverageService::class)->report($emission->fresh());
    $generation = app(PuCurveGenerationService::class)->handle($emission->fresh());
    $curveRow = collect($generation->rows)->first(
        fn ($row): bool => $row->date->toDateString() === $curveDate,
    );

    expect($requirement->lookupDate?->toDateString())->toBe($requiredRateDate)
        ->and($requirement->requiredRateDate()?->toDateString())->toBe($requiredRateDate)
        ->and($requirement->ruleDescription())->toBe('D-1 calendário')
        ->and($requirement->isRequiredForCalculation())->toBeTrue()
        ->and($prerequisite->passes())->toBeTrue()
        ->and($coverage->missingIndexDates)->toBe([])
        ->and($curveRow)->not->toBeNull()
        ->and($curveRow->indexRateDate?->toDateString())->toBe($requiredRateDate)
        ->and(bccomp($curveRow->factorDi, '1', 8))->toBe(1);

    IndexRate::query()->whereDate('rate_date', $requiredRateDate)->delete();
    app(IndexRateService::class)->flushCache();

    $missingCoverage = app(PuIndexCoverageService::class)->report($emission->fresh());
    $missingPrerequisite = app(PuCurvePrerequisiteService::class)->handle($emission->fresh());

    expect($missingCoverage->missingIndexDates)->toBe([$requiredRateDate])
        ->and($missingCoverage->missingIndexMessages)->toHaveCount(1)
        ->and($missingCoverage->missingIndexMessages[0])->toContain("Taxa DI ausente para {$requiredRateDate}.")
        ->and($missingCoverage->missingIndexMessages[0])->toContain("Data da curva: {$curveDate}")
        ->and($missingCoverage->missingIndexMessages[0])->toContain('Modo: PreviousCalendarDayExact')
        ->and($missingCoverage->missingIndexMessages[0])->toContain('Regra: D-1 calendário')
        ->and($missingPrerequisite->passes())->toBeFalse()
        ->and($missingPrerequisite->blockingSummary())->toContain("Data requerida: {$requiredRateDate}");
})->with('phase2b3_previous_calendar_day_cases');

dataset('phase2b3_business_day_lag_cases', [
    'lag negativo de um dia util' => ['2026-08-10', -1, '2026-08-07', 'B3', [], true],
    'lag positivo de um dia util' => ['2026-08-07', 1, '2026-08-10', 'B3', [], true],
    'lag de cinco com feriado no meio' => ['2026-08-10', -5, '2026-07-31', 'B3', ['2026-08-05'], true],
    'data da curva no fim de semana' => ['2026-08-09', -1, '2026-08-07', 'B3', [], false],
    'virada de mes' => ['2026-09-01', -1, '2026-08-31', 'B3', [], true],
    'virada de ano com feriado' => ['2027-01-04', -1, '2026-12-31', 'B3', ['2027-01-01'], true],
    'calendario HML isolado' => ['2026-03-03', -1, '2026-02-27', 'HML_PU_PHASE_2B3', ['2026-03-02'], true],
]);

it('resolves exact business-day lags with the configured calendar', function (
    string $curveDate,
    int $lag,
    string $requiredRateDate,
    string $calendarCode,
    array $holidays,
    bool $isRequiredForCalculation,
) {
    $rangeStart = min($curveDate, $requiredRateDate);
    $rangeEnd = max($curveDate, $requiredRateDate);
    phase2b3SeedCalendar($rangeStart, $rangeEnd, $calendarCode, $holidays);
    $parameter = phase2b3Parameter(
        PuIndexRateLookupMode::BusinessDayLagExact,
        $calendarCode,
        $lag,
    );

    $requirement = app(PuIndexRateRequirementResolver::class)->resolve(
        $parameter,
        CarbonImmutable::parse($curveDate),
    );

    expect($requirement->lookupDate?->toDateString())->toBe($requiredRateDate)
        ->and($requirement->requiredRateDate()?->toDateString())->toBe($requiredRateDate)
        ->and($requirement->isRequiredForCalculation())->toBe($isRequiredForCalculation)
        ->and($requirement->ruleDescription())->toContain((string) $lag)
        ->and($requirement->ruleDescription())->toContain($calendarCode);
})->with('phase2b3_business_day_lag_cases');

it('keeps business-day lag lookup separate from financial application and aligns all consumers', function () {
    $emission = phase2b3Emission(
        '2026-09-03',
        '2026-09-08',
        PuIndexRateLookupMode::BusinessDayLagExact,
        businessDayLag: -1,
    );
    phase2b3SeedCalendar('2026-09-02', '2026-09-08', holidays: ['2026-09-07']);
    phase2b3SeedCdi('2026-09-02');
    phase2b3SeedCdi('2026-09-03');
    phase2b3SeedCdi('2026-09-04');

    $prerequisite = app(PuCurvePrerequisiteService::class)->handle($emission->fresh());
    $coverage = app(PuIndexCoverageService::class)->report($emission->fresh());
    $generation = app(PuCurveGenerationService::class)->handle($emission->fresh());
    $holidayRow = collect($generation->rows)->first(
        fn ($row): bool => $row->date->toDateString() === '2026-09-07',
    );
    $nextBusinessDayRow = collect($generation->rows)->first(
        fn ($row): bool => $row->date->toDateString() === '2026-09-08',
    );

    expect($prerequisite->passes())->toBeTrue()
        ->and($coverage->missingIndexDates)->toBe([])
        ->and($holidayRow->indexRateDate?->toDateString())->toBe('2026-09-04')
        ->and($holidayRow->factorDi)->toBe('1.0000000000000000')
        ->and($nextBusinessDayRow->indexRateDate?->toDateString())->toBe('2026-09-04')
        ->and(bccomp($nextBusinessDayRow->factorDi, '1', 8))->toBe(1);

    IndexRate::query()->whereDate('rate_date', '2026-09-04')->delete();
    app(IndexRateService::class)->flushCache();

    $pendingCoverage = app(PuIndexCoverageService::class)->report($emission->fresh());
    $pendingPrerequisite = app(PuCurvePrerequisiteService::class)->handle($emission->fresh());
    $pendingWarning = collect($pendingPrerequisite->warningMessages())
        ->first(fn (string $message): bool => str_contains($message, 'aguardam a publicacao do CDI'));

    expect($pendingCoverage->missingIndexDates)->toBe([])
        ->and($pendingCoverage->pendingIndexDates)->toBe(['2026-09-04'])
        ->and($pendingCoverage->pendingIndexMessages)->toHaveCount(1)
        ->and($pendingPrerequisite->passes())->toBeTrue()
        ->and($pendingWarning)->not->toBeNull();

    phase2b3SeedCdi('2026-09-08');

    $missingCoverage = app(PuIndexCoverageService::class)->report($emission->fresh());
    $missingPrerequisite = app(PuCurvePrerequisiteService::class)->handle($emission->fresh());

    expect($missingCoverage->missingIndexDates)->toBe(['2026-09-04'])
        ->and($missingCoverage->missingIndexMessages)->toHaveCount(1)
        ->and($missingPrerequisite->blockingSummary())->toContain('Data da curva: 2026-09-08')
        ->and($missingPrerequisite->blockingSummary())->toContain('Modo: BusinessDayLagExact')
        ->and($missingPrerequisite->blockingSummary())->toContain('calendário B3')
        ->and($missingPrerequisite->blockingSummary())->toContain('Data requerida: 2026-09-04');
});

dataset('phase2b3_previous_available_cases', [
    'dia util comum usa a taxa do proprio dia' => ['2026-08-06', [], ['2026-08-06'], '2026-08-06', true],
    'segunda-feira usa a ultima taxa disponivel da sexta' => ['2026-08-10', [], ['2026-08-07'], '2026-08-07', true],
    'feriado nao consulta taxa' => ['2026-08-12', ['2026-08-12'], ['2026-08-11'], null, false],
    'sequencia feriado e fim de semana usa a taxa anterior' => ['2026-09-08', ['2026-09-04', '2026-09-07'], ['2026-09-03'], '2026-09-03', true],
    'ausencia de indice anterior permanece ausente' => ['2026-08-10', [], [], null, true],
]);

it('preserves the actual previous-available engine semantics', function (
    string $curveDate,
    array $holidays,
    array $availableRateDates,
    ?string $resolvedRateDate,
    bool $isRequiredForCalculation,
) {
    $rangeStart = CarbonImmutable::parse($curveDate)->subDays(7)->toDateString();
    phase2b3SeedCalendar($rangeStart, $curveDate, holidays: $holidays);

    foreach ($availableRateDates as $availableRateDate) {
        phase2b3SeedCdi($availableRateDate);
    }

    $parameter = phase2b3Parameter(PuIndexRateLookupMode::PreviousAvailableBusinessDay);
    $requirement = app(PuIndexRateRequirementResolver::class)->resolve(
        $parameter,
        CarbonImmutable::parse($curveDate),
    );

    expect($requirement->rate?->date->toDateString())->toBe($resolvedRateDate)
        ->and($requirement->isRequiredForCalculation())->toBe($isRequiredForCalculation)
        ->and($requirement->lookupDate?->toDateString())->toBe($isRequiredForCalculation ? $curveDate : null);

    if ($isRequiredForCalculation && $resolvedRateDate === null) {
        expect($requirement->missingRateMessage())
            ->toContain("Data da curva: {$curveDate}")
            ->toContain('Modo: PreviousAvailableBusinessDay');
    }
})->with('phase2b3_previous_available_cases');

it('uses one previous available snapshot without producing false coverage gaps', function () {
    $emission = phase2b3Emission(
        '2026-09-03',
        '2026-09-08',
        PuIndexRateLookupMode::PreviousAvailableBusinessDay,
    );
    phase2b3SeedCalendar('2026-09-03', '2026-09-08', holidays: ['2026-09-04', '2026-09-07']);
    phase2b3SeedCdi('2026-09-03');

    $prerequisite = app(PuCurvePrerequisiteService::class)->handle($emission->fresh());
    $coverage = app(PuIndexCoverageService::class)->report($emission->fresh());
    $generation = app(PuCurveGenerationService::class)->handle($emission->fresh());
    $septemberEighth = collect($generation->rows)->first(
        fn ($row): bool => $row->date->toDateString() === '2026-09-08',
    );

    expect($prerequisite->passes())->toBeTrue()
        ->and($coverage->missingIndexDates)->toBe([])
        ->and($septemberEighth->indexRateDate?->toDateString())->toBe('2026-09-03')
        ->and(bccomp($septemberEighth->factorDi, '1', 8))->toBe(1);
});

it('does not require a lagged snapshot that the engine will not apply on non-business dates', function () {
    $emission = phase2b3Emission(
        '2026-09-04',
        '2026-09-07',
        PuIndexRateLookupMode::BusinessDayLagExact,
        businessDayLag: -1,
    );
    phase2b3SeedCalendar('2026-09-03', '2026-09-07', holidays: ['2026-09-07']);

    $prerequisite = app(PuCurvePrerequisiteService::class)->handle($emission->fresh());
    $coverage = app(PuIndexCoverageService::class)->report($emission->fresh());
    $generation = app(PuCurveGenerationService::class)->handle($emission->fresh());

    expect($prerequisite->passes())->toBeTrue()
        ->and($coverage->missingIndexDates)->toBe([])
        ->and($coverage->pendingIndexDates)->toBe([])
        ->and(collect($generation->rows)->pluck('factorDi')->unique()->values()->all())
        ->toBe(['1.0000000000000000']);
});

it('renders the exact missing Taxa DI context in the administrative command', function () {
    $emission = phase2b3Emission(
        '2026-08-09',
        '2026-08-10',
        PuIndexRateLookupMode::PreviousCalendarDayExact,
    );
    phase2b3SeedCalendar('2026-08-09', '2026-08-10');

    $this->artisan('pu:check-missing-data', ['emission' => $emission->id])
        ->expectsOutputToContain('Taxa DI ausente para 2026-08-09.')
        ->expectsOutputToContain('Data da curva: 2026-08-10')
        ->expectsOutputToContain('Modo: PreviousCalendarDayExact')
        ->expectsOutputToContain('Regra: D-1 calendário')
        ->assertFailed();
});
