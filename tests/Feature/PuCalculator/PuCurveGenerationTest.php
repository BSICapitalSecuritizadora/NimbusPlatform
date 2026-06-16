<?php

use App\Actions\Emissions\GeneratePuDailyCurve;
use App\Domain\PuCalculator\Services\DecimalRounder;
use App\Models\BusinessCalendarDate;
use App\Models\Emission;
use App\Models\Payment;
use App\Models\PuHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('generates and persists a deterministic zero-rate curve with legacy projections', function () {
    $emission = Emission::factory()->create([
        'type' => 'CRI',
        'status' => 'active',
    ]);

    seedBusinessCalendar('2026-01-01', '2026-01-05');
    seedFixedCdiRates('2026-01-01', '2026-01-05', '0.00000000');

    $emission->integralizationHistories()->create([
        'date' => '2026-01-01',
        'quantity' => '100.0000',
        'unit_value' => '1000.00000000',
        'financial_value' => '100000.00',
        'investor_fund' => 'Head Invest',
    ]);

    $emission->puParameter()->create([
        'curve_start_date' => '2026-01-01',
        'curve_end_date' => '2026-01-05',
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '0.00000000',
        'indexer' => 'CDI',
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'legacy_projection_enabled' => true,
    ]);

    $emission->puEvents()->create([
        'event_type' => 'interest_payment',
        'original_date' => '2026-01-05',
        'effective_date' => '2026-01-05',
        'amortization_type' => 'none',
        'amortization_value' => null,
        'sequence' => 1,
    ]);

    $emission->puEvents()->create([
        'event_type' => 'amortization',
        'original_date' => '2026-01-05',
        'effective_date' => '2026-01-05',
        'amortization_type' => 'unit_value',
        'amortization_value' => '100.0000000000000000',
        'sequence' => 2,
    ]);

    $result = app(GeneratePuDailyCurve::class)->handle($emission);

    expect($result->rows)->toHaveCount(5)
        ->and($emission->puDailyCurves()->count())->toBe(5)
        ->and(PuHistory::query()->where('emission_id', $emission->id)->count())->toBe(5)
        ->and(Payment::query()->where('emission_id', $emission->id)->count())->toBe(1);

    $eventRow = $emission->puDailyCurves()->whereDate('curve_date', '2026-01-05')->sole();
    $payment = Payment::query()->where('emission_id', $emission->id)->sole();

    expect($eventRow->payment_total_unit_value)->toBe('100.0000000000000000')
        ->and($eventRow->payment_total_value)->toBe('10000.0000000000000000')
        ->and($eventRow->residual_unit_value)->toBe('900.0000000000000000')
        ->and($payment->interest_value)->toBe('0.00')
        ->and($payment->amortization_value)->toBe('10000.00')
        ->and(bccomp((string) $emission->fresh()->getRawOriginal('current_pu'), '900', 6))->toBe(0);
});

it('uses cumulative quantity, keeps factor identities and resets the base after a payment event', function () {
    $emission = Emission::factory()->create([
        'type' => 'CRI',
        'status' => 'active',
    ]);

    seedBusinessCalendar('2026-03-02', '2026-03-10');
    seedFixedCdiRates('2026-03-02', '2026-03-10', '14.90000000');

    $emission->integralizationHistories()->create([
        'date' => '2026-03-02',
        'quantity' => '7000.0000',
        'unit_value' => '1000.00000000',
        'financial_value' => '7000000.00',
        'investor_fund' => 'Head Invest',
    ]);

    $emission->integralizationHistories()->create([
        'date' => '2026-03-06',
        'quantity' => '1000.0000',
        'unit_value' => '1000.00000000',
        'financial_value' => '1000000.00',
        'investor_fund' => 'Troupe FIM',
    ]);

    $emission->puParameter()->create([
        'curve_start_date' => '2026-03-02',
        'curve_end_date' => '2026-03-10',
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '6.50000000',
        'indexer' => 'CDI',
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'legacy_projection_enabled' => true,
    ]);

    $emission->puEvents()->create([
        'event_type' => 'interest_payment',
        'original_date' => '2026-03-09',
        'effective_date' => '2026-03-09',
        'amortization_type' => 'none',
        'amortization_value' => null,
        'sequence' => 1,
    ]);

    app(GeneratePuDailyCurve::class)->handle($emission);

    $marchSixth = $emission->puDailyCurves()->whereDate('curve_date', '2026-03-06')->sole();
    $marchNinth = $emission->puDailyCurves()->whereDate('curve_date', '2026-03-09')->sole();
    $marchTenth = $emission->puDailyCurves()->whereDate('curve_date', '2026-03-10')->sole();

    $expectedFactorSpreadDi = roundDecimal(
        bcmul((string) $marchNinth->factor_di_accumulated, (string) $marchNinth->factor_spread, DecimalRounder::INTERNAL_SCALE),
        DecimalRounder::FACTOR_SCALE,
    );
    $expectedInterest = roundDecimal(
        bcmul(
            (string) $marchNinth->unit_base_value,
            bcsub($expectedFactorSpreadDi, '1', DecimalRounder::INTERNAL_SCALE),
            DecimalRounder::INTERNAL_SCALE,
        ),
        DecimalRounder::UNIT_SCALE,
    );

    expect($marchSixth->quantity)->toBe('8000.0000')
        ->and(bccomp((string) $marchNinth->interest_payment_unit_value, '0', DecimalRounder::UNIT_SCALE))->toBe(1)
        ->and(bccomp((string) $marchNinth->factor_spread_di, $expectedFactorSpreadDi, 12))->toBe(0)
        ->and(bccomp((string) $marchNinth->interest_real_unit_value, $expectedInterest, 8))->toBe(0)
        ->and((string) $marchNinth->payment_total_value)->toBe((string) $marchNinth->interest_payment_value)
        ->and((string) $marchTenth->unit_base_value)->toBe((string) $marchNinth->residual_unit_value);
});

function seedBusinessCalendar(string $startDate, string $endDate): void
{
    for ($date = \Carbon\CarbonImmutable::parse($startDate); $date->lte(\Carbon\CarbonImmutable::parse($endDate)); $date = $date->addDay()) {
        BusinessCalendarDate::query()->create([
            'calendar_code' => 'B3',
            'calendar_date' => $date->toDateString(),
            'is_business_day' => ! $date->isWeekend(),
            'description' => null,
        ]);
    }
}

function seedFixedCdiRates(string $startDate, string $endDate, string $rateValue): void
{
    for ($date = \Carbon\CarbonImmutable::parse($startDate); $date->lte(\Carbon\CarbonImmutable::parse($endDate)); $date = $date->addDay()) {
        if ($date->isWeekend()) {
            continue;
        }

        \App\Models\IndexRate::query()->create([
            'indexer' => 'CDI',
            'rate_date' => $date->toDateString(),
            'rate_value' => $rateValue,
            'source' => 'testing',
            'source_reference' => 'fixed-rate',
        ]);
    }
}

function roundDecimal(string $value, int $scale): string
{
    $offset = '0.'.str_repeat('0', max(0, $scale)).'5';

    if (bccomp($value, '0', DecimalRounder::INTERNAL_SCALE) < 0) {
        return bcsub($value, $offset, $scale);
    }

    return bcadd($value, $offset, $scale);
}
