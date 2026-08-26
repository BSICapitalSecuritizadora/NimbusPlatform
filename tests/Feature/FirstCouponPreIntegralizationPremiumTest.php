<?php

use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Services\FirstCouponPreIntegralizationPremiumCalculator;
use App\Domain\PuCalculator\Services\PuCurveGenerationService;
use App\Domain\PuCalculator\Services\PuCurvePrerequisiteService;
use App\Domain\PuCalculator\Services\PuFirstCouponPremiumHomologationService;
use App\Domain\PuCalculator\Services\PuIndexCoverageService;
use App\Domain\PuCalculator\Services\PuIndexRateRequirementResolver;
use App\Models\BusinessCalendarDate;
use App\Models\Emission;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuParameter;
use App\Models\IndexRate;
use App\Models\Payment;
use App\Models\PuHistory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps the first coupon pre-integralization premium disabled by default', function () {
    $parameter = EmissionPuParameter::factory()->create();

    expect($parameter->first_coupon_pre_integralization_premium_enabled)->toBeFalse()
        ->and($parameter->first_coupon_pre_integralization_business_days)->toBeNull()
        ->and($parameter->first_coupon_pre_integralization_apply_index_factor)->toBeTrue()
        ->and($parameter->first_coupon_pre_integralization_apply_spread_factor)->toBeTrue();
});

it('resolves exactly the N business days before start across calendar boundaries', function (
    string $calendarStart,
    string $curveStart,
    array $holidays,
    array $expectedDates,
) {
    premiumSeedCalendar($calendarStart, $curveStart, $holidays);
    $parameter = EmissionPuParameter::factory()->create([
        'curve_start_date' => $curveStart,
        'curve_end_date' => CarbonImmutable::parse($curveStart)->addDays(2)->toDateString(),
        'first_coupon_pre_integralization_premium_enabled' => true,
        'first_coupon_pre_integralization_business_days' => 2,
    ]);

    $dates = app(PuIndexRateRequirementResolver::class)
        ->firstCouponPreIntegralizationAccrualDates($parameter);

    expect(array_map(
        fn (CarbonImmutable $date): string => $date->toDateString(),
        $dates,
    ))->toBe($expectedDates);
})->with([
    'final de semana' => ['2026-01-05', '2026-01-12', [], ['2026-01-08', '2026-01-09']],
    'feriado' => ['2026-01-05', '2026-01-12', ['2026-01-09'], ['2026-01-07', '2026-01-08']],
    'virada de mês' => ['2026-01-26', '2026-02-02', [], ['2026-01-29', '2026-01-30']],
    'virada de ano' => ['2026-12-28', '2027-01-04', ['2027-01-01'], ['2026-12-30', '2026-12-31']],
]);

it('uses BusinessDayLagExact independently for each premium accrual day', function () {
    premiumSeedCalendar('2025-12-20', '2026-01-12', ['2026-01-01']);
    premiumSeedRates('2025-12-20', '2026-01-12', '14.90000000', ['2026-01-01']);
    $parameter = EmissionPuParameter::factory()->create([
        'curve_start_date' => '2026-01-12',
        'curve_end_date' => '2026-01-16',
        'index_rate_lookup_mode' => PuIndexRateLookupMode::BusinessDayLagExact->value,
        'index_rate_lag_business_days' => -5,
        'first_coupon_pre_integralization_premium_enabled' => true,
        'first_coupon_pre_integralization_business_days' => 2,
    ]);

    $requirements = app(PuIndexRateRequirementResolver::class)
        ->firstCouponPreIntegralizationRateRequirements($parameter);

    expect(collect($requirements)->map(fn ($requirement): array => [
        $requirement->curveDate->toDateString(),
        $requirement->requiredRateDate()?->toDateString(),
    ])->all())->toBe([
        ['2026-01-08', '2025-12-31'],
        ['2026-01-09', '2026-01-02'],
    ])->and(app(PuIndexRateRequirementResolver::class)
        ->firstCouponPreIntegralizationFinancialCalendarStartDate($parameter)?->toDateString())
        ->toBe('2025-12-31');
});

it('calculates the controlled two-day DI and spread premium exactly', function () {
    $emission = premiumCreateEmission();
    $premium = app(FirstCouponPreIntegralizationPremiumCalculator::class)
        ->calculate($emission->puParameter);

    expect($premium)->not->toBeNull()
        ->and($premium?->businessDays)->toBe(2)
        ->and(collect($premium?->accrualDays)->pluck('accrual_date')->all())->toBe([
            '2026-01-08',
            '2026-01-09',
        ])
        ->and(collect($premium?->accrualDays)->pluck('required_index_rate_date')->all())->toBe([
            '2025-12-31',
            '2026-01-02',
        ])
        ->and(collect($premium?->accrualDays)->pluck('factor_di')->all())->toBe([
            '1.0005513100000000',
            '1.0005513100000000',
        ])
        ->and(collect($premium?->accrualDays)->pluck('factor_spread')->all())->toBe([
            '1.0002312530000000',
            '1.0002312530000000',
        ])
        ->and($premium?->factorDi)->toBe('1.0011029200000000')
        ->and($premium?->factorSpread)->toBe('1.0004625590000000')
        ->and($premium?->factor)->toBe('1.0015659890000000');
});

it('applies the premium once to the first interest coupon without changing principal or creating pre-start rows', function () {
    $emission = premiumCreateEmission();
    $rows = app(PuCurveGenerationService::class)->handle($emission)->rows;
    $rowsByDate = collect($rows)->keyBy(fn (PuDailyCurveRowData $row): string => $row->date->toDateString());
    /** @var PuDailyCurveRowData $start */
    $start = $rowsByDate->get('2026-01-12');
    /** @var PuDailyCurveRowData $firstCoupon */
    $firstCoupon = $rowsByDate->get('2026-01-14');
    /** @var PuDailyCurveRowData $secondCoupon */
    $secondCoupon = $rowsByDate->get('2026-01-16');

    expect($rows)->toHaveCount(5)
        ->and($rows[0]->date->toDateString())->toBe('2026-01-12')
        ->and($start->unitBaseValue)->toBe('1000.0000000000000000')
        ->and($start->updatedUnitValue)->toBe('1000.0000000000000000')
        ->and($firstCoupon->factorSpreadDi)->toBe('1.0031344300000000')
        ->and($firstCoupon->interestPaymentUnitValue)->toBe('3.1344300000000000')
        ->and($firstCoupon->amortizationUnitValue)->toBe('0.0000000000000000')
        ->and($firstCoupon->residualUnitValue)->toBe('1000.0000000000000000')
        ->and($firstCoupon->calculationMemory['first_coupon_pre_integralization_premium_applied'])->toBeTrue()
        ->and($firstCoupon->calculationMemory['first_coupon_pre_integralization_premium']['factor'])->toBe('1.0015659890000000')
        ->and($secondCoupon->factorSpreadDi)->toBe('1.0015659891655723')
        ->and($secondCoupon->interestPaymentUnitValue)->toBe('1.5659890000000000')
        ->and($secondCoupon->residualUnitValue)->toBe('1000.0000000000000000')
        ->and($secondCoupon->calculationMemory['first_coupon_pre_integralization_premium_applied'])->toBeFalse()
        ->and($secondCoupon->calculationMemory['first_coupon_pre_integralization_premium'])->toBeNull();
});

it('reports a missing lagged DI snapshot from before curve start in prerequisite and coverage', function () {
    $emission = premiumCreateEmission(['2025-12-31']);

    $prerequisite = app(PuCurvePrerequisiteService::class)->handle($emission->fresh());
    $coverage = app(PuIndexCoverageService::class)->report($emission->fresh());

    expect($prerequisite->passes())->toBeFalse()
        ->and($prerequisite->blockingSummary())->toContain('prêmio pré-integralização')
        ->and($prerequisite->blockingSummary())->toContain('2025-12-31')
        ->and($coverage->financialRequirementStartDate)->toBe('2025-12-31')
        ->and($coverage->missingIndexDates)->toContain('2025-12-31')
        ->and(implode("\n", $coverage->missingIndexMessages))->toContain('prêmio pré-integralização');
});

it('blocks an enabled premium without a positive business day count or both financial factors', function () {
    $emission = premiumCreateEmission();
    $emission->puParameter->forceFill([
        'first_coupon_pre_integralization_business_days' => 0,
        'first_coupon_pre_integralization_apply_index_factor' => false,
        'first_coupon_pre_integralization_apply_spread_factor' => false,
    ])->save();

    $prerequisite = app(PuCurvePrerequisiteService::class)->handle($emission->fresh());

    expect($prerequisite->passes())->toBeFalse()
        ->and($prerequisite->blockingSummary())->toContain('quantidade de Dias Úteis maior que zero')
        ->and($prerequisite->blockingSummary())->toContain('ao menos o Fator DI ou o Fator Spread');
});

it('blocks an enabled premium when the first interest payment is outside the curve', function () {
    $emission = premiumCreateEmission();
    $emission->puParameter->forceFill([
        'curve_end_date' => '2026-01-13',
    ])->save();

    $prerequisite = app(PuCurvePrerequisiteService::class)->handle($emission->fresh());

    expect($prerequisite->passes())->toBeFalse()
        ->and($prerequisite->blockingSummary())->toContain('primeiro pagamento de juros')
        ->and($prerequisite->blockingSummary())->toContain('dentro do período da curva');
});

it('compares scenarios with and without premium without persisting financial side effects', function () {
    $emission = premiumCreateEmission();
    $emission->puParameter->forceFill([
        'first_coupon_pre_integralization_premium_enabled' => false,
        'first_coupon_pre_integralization_business_days' => null,
    ])->save();
    $before = premiumFinancialCounts($emission);

    $comparison = app(PuFirstCouponPremiumHomologationService::class)->compare($emission->fresh(), 2);

    expect($comparison['interest_payments'])->toHaveCount(2)
        ->and($comparison['interest_payments'][0]['with_premium']['premium_applied'])->toBeTrue()
        ->and($comparison['interest_payments'][0]['interest_payment_unit_value_difference'])->toBe('1.5684410000000000')
        ->and($comparison['interest_payments'][1]['with_premium']['premium_applied'])->toBeFalse()
        ->and($comparison['interest_payments'][1]['interest_payment_unit_value_difference'])->toBe('0.0000000000000000')
        ->and($comparison['checksum'])->toHaveLength(64)
        ->and(premiumFinancialCounts($emission))->toBe($before);
});

it('leaves curve results identical when the feature is absent or explicitly disabled', function (PuIndexRateLookupMode $lookupMode) {
    $emission = premiumCreateRegressionEmission($lookupMode);
    $parameter = $emission->puParameter;
    $defaultRows = app(PuCurveGenerationService::class)->handle($emission)->rows;
    $explicitlyDisabled = $parameter->replicate()->forceFill([
        'first_coupon_pre_integralization_premium_enabled' => false,
        'first_coupon_pre_integralization_business_days' => 99,
        'first_coupon_pre_integralization_apply_index_factor' => false,
        'first_coupon_pre_integralization_apply_spread_factor' => false,
    ]);
    $scenario = clone $emission;
    $scenario->setRelation('puParameter', $explicitlyDisabled);
    $disabledRows = app(PuCurveGenerationService::class)->handle($scenario)->rows;

    expect(array_map(
        fn (PuDailyCurveRowData $row): array => $row->toPersistenceArray($emission->id, 'regression'),
        $disabledRows,
    ))->toBe(array_map(
        fn (PuDailyCurveRowData $row): array => $row->toPersistenceArray($emission->id, 'regression'),
        $defaultRows,
    ));
})->with([
    PuIndexRateLookupMode::PreviousAvailableBusinessDay,
    PuIndexRateLookupMode::PreviousCalendarDayExact,
    PuIndexRateLookupMode::BusinessDayLagExact,
]);

/** @param  list<string>  $missingRateDates */
function premiumCreateEmission(array $missingRateDates = []): Emission
{
    premiumSeedCalendar('2025-12-20', '2026-01-16', ['2026-01-01']);
    premiumSeedRates('2025-12-20', '2026-01-16', '14.90000000', ['2026-01-01', ...$missingRateDates]);
    $emission = Emission::factory()->create([
        'type' => 'CRI',
        'status' => 'active',
        'issued_quantity' => 100,
    ]);
    $emission->integralizationHistories()->create([
        'date' => '2026-01-12',
        'quantity' => '10.0000',
        'unit_value' => '1000.00000000',
        'financial_value' => '10000.00',
        'investor_fund' => 'Fixture controlada',
    ]);
    $emission->puParameter()->create([
        'curve_start_date' => '2026-01-12',
        'curve_end_date' => '2026-01-16',
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '6.00000000',
        'indexer' => PuIndexer::Cdi->value,
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'index_rate_lookup_mode' => PuIndexRateLookupMode::BusinessDayLagExact->value,
        'index_rate_lag_business_days' => -5,
        'first_coupon_pre_integralization_premium_enabled' => true,
        'first_coupon_pre_integralization_business_days' => 2,
        'first_coupon_pre_integralization_apply_index_factor' => true,
        'first_coupon_pre_integralization_apply_spread_factor' => true,
        'legacy_projection_enabled' => false,
    ]);

    foreach (['2026-01-14', '2026-01-16'] as $sequence => $date) {
        $emission->puEvents()->create([
            'event_type' => 'interest_payment',
            'original_date' => $date,
            'effective_date' => $date,
            'amortization_type' => 'none',
            'sequence' => $sequence + 1,
        ]);
    }

    return $emission->fresh(['puParameter', 'puEvents', 'integralizationHistories']);
}

function premiumCreateRegressionEmission(PuIndexRateLookupMode $lookupMode): Emission
{
    premiumSeedCalendar('2026-02-20', '2026-03-10');
    premiumSeedRates('2026-02-20', '2026-03-10', '14.90000000');
    $emission = Emission::factory()->create(['issued_quantity' => 100]);
    $emission->integralizationHistories()->create([
        'date' => '2026-03-02',
        'quantity' => '10.0000',
        'unit_value' => '1000.00000000',
        'financial_value' => '10000.00',
        'investor_fund' => 'Fixture de regressão',
    ]);
    $emission->puParameter()->create([
        'curve_start_date' => '2026-03-02',
        'curve_end_date' => '2026-03-06',
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '6.50000000',
        'indexer' => PuIndexer::Cdi->value,
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'index_rate_lookup_mode' => $lookupMode->value,
        'index_rate_lag_business_days' => $lookupMode === PuIndexRateLookupMode::BusinessDayLagExact ? -1 : 1,
        'legacy_projection_enabled' => false,
    ]);

    return $emission->fresh(['puParameter', 'puEvents', 'integralizationHistories']);
}

/** @param  list<string>  $holidays */
function premiumSeedCalendar(string $startDate, string $endDate, array $holidays = []): void
{
    for ($date = CarbonImmutable::parse($startDate); $date->lte(CarbonImmutable::parse($endDate)); $date = $date->addDay()) {
        BusinessCalendarDate::query()->updateOrCreate(
            ['calendar_code' => 'B3', 'calendar_date' => $date->toDateString()],
            [
                'is_business_day' => ! $date->isWeekend() && ! in_array($date->toDateString(), $holidays, true),
                'description' => in_array($date->toDateString(), $holidays, true) ? 'Feriado da fixture' : null,
            ],
        );
    }
}

/** @param  list<string>  $excludedDates */
function premiumSeedRates(string $startDate, string $endDate, string $rate, array $excludedDates = []): void
{
    for ($date = CarbonImmutable::parse($startDate); $date->lte(CarbonImmutable::parse($endDate)); $date = $date->addDay()) {
        if ($date->isWeekend() || in_array($date->toDateString(), $excludedDates, true)) {
            continue;
        }

        IndexRate::query()->updateOrCreate(
            ['indexer' => PuIndexer::Cdi->value, 'rate_date' => $date->toDateString()],
            [
                'rate_value' => $rate,
                'source' => 'testing',
                'source_reference' => 'first-coupon-premium-fixture',
            ],
        );
    }
}

/** @return array<string, int> */
function premiumFinancialCounts(Emission $emission): array
{
    return [
        'parameters' => EmissionPuParameter::query()->where('emission_id', $emission->id)->count(),
        'curves' => EmissionPuDailyCurve::query()->where('emission_id', $emission->id)->count(),
        'histories' => PuHistory::query()->where('emission_id', $emission->id)->count(),
        'payments' => Payment::query()->where('emission_id', $emission->id)->count(),
        'events' => $emission->puEvents()->count(),
        'integralizations' => $emission->integralizationHistories()->count(),
    ];
}
