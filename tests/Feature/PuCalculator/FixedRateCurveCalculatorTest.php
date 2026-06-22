<?php

use App\Actions\Emissions\GeneratePuDailyCurve;
use App\Domain\PuCalculator\Calculators\DailyFactorCalculator;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Services\DecimalRounder;
use App\Models\BusinessCalendarDate;
use App\Models\Emission;
use App\Models\IndexRate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedPrefixedCalendar(string $startDate, string $endDate): void
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

function makePrefixedEmission(string $start = '2026-02-02', string $end = '2026-02-04', string $annualRate = '10.00000000'): Emission
{
    $emission = Emission::factory()->create(['type' => 'CRI', 'status' => 'active', 'issued_quantity' => 1000]);

    seedPrefixedCalendar($start, $end);

    $emission->integralizationHistories()->create([
        'date' => $start,
        'quantity' => '10.0000',
        'unit_value' => '1000.00000000',
        'financial_value' => '10000.00',
        'investor_fund' => 'Head Invest',
    ]);

    $emission->puParameter()->create([
        'curve_start_date' => $start,
        'curve_end_date' => $end,
        'initial_unit_value' => '1000.0000000000000000',
        'annual_rate' => $annualRate,
        'indexer' => 'PREFIXED',
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'legacy_projection_enabled' => false,
    ]);

    return $emission;
}

it('generates a prefixed curve without requiring CDI', function () {
    $emission = makePrefixedEmission();

    expect(IndexRate::query()->count())->toBe(0); // nenhum CDI cadastrado

    app(GeneratePuDailyCurve::class)->handle($emission, syncLegacyProjections: false);

    $rows = $emission->fresh()->puDailyCurves()->orderBy('curve_date')->get();

    expect($rows)->toHaveCount(3)
        ->and((string) $rows[0]->updated_unit_value)->toBe('1000.0000000000000000')
        ->and($rows[0]->dup_interest)->toBe(0)
        ->and($rows[1]->index_rate_value)->toBeNull()
        ->and($rows[1]->dup_interest)->toBe(1);
});

it('applies the annual fixed factor (1 + rate/100)^(DUP/252)', function () {
    $emission = makePrefixedEmission();

    app(GeneratePuDailyCurve::class)->handle($emission, syncLegacyProjections: false);

    $secondRow = $emission->fresh()->puDailyCurves()->orderBy('curve_date')->get()[1];

    $rounder = app(DecimalRounder::class);
    $expectedFactor = $rounder->round(
        app(DailyFactorCalculator::class)->factorSpreadForBusinessDays('10.00000000', 1, 252, DecimalRounder::CALCULATION_SCALE),
        DecimalRounder::FACTOR_SCALE,
    );

    // Compara em 12 casas: o cast decimal:16 do model formata via float e perde 1 ULP no 16º dígito.
    expect(bccomp((string) $secondRow->factor_spread, $expectedFactor, 12))->toBe(0)
        ->and(bccomp((string) $secondRow->updated_unit_value, '1000.0000000000000000', 8))->toBe(1); // juros acumulado
});

it('respects interest payment events and resets the base afterwards', function () {
    $emission = makePrefixedEmission('2026-02-02', '2026-02-05');

    $emission->puEvents()->create([
        'event_type' => PuEventType::InterestPayment->value,
        'effective_date' => '2026-02-04',
        'amortization_type' => PuAmortizationType::None->value,
        'sequence' => 1,
    ]);

    app(GeneratePuDailyCurve::class)->handle($emission, syncLegacyProjections: false);

    $rows = $emission->fresh()->puDailyCurves()->orderBy('curve_date')->get()->keyBy(fn ($r) => $r->curve_date->toDateString());

    expect(bccomp((string) $rows['2026-02-04']->payment_total_value, '0', 8))->toBe(1)
        ->and((string) $rows['2026-02-05']->dup_interest)->toBe('1'); // base resetada após pagamento
});

it('respects ordinary amortization reducing the residual', function () {
    $emission = makePrefixedEmission('2026-02-02', '2026-02-05');

    $emission->puEvents()->create([
        'event_type' => PuEventType::Amortization->value,
        'effective_date' => '2026-02-04',
        'amortization_type' => PuAmortizationType::Percentage->value,
        'amortization_value' => '0.1000000000000000',
        'sequence' => 1,
    ]);

    app(GeneratePuDailyCurve::class)->handle($emission, syncLegacyProjections: false);

    $rows = $emission->fresh()->puDailyCurves()->orderBy('curve_date')->get()->keyBy(fn ($r) => $r->curve_date->toDateString());

    expect(bccomp((string) $rows['2026-02-04']->amortization_unit_value, '0', 8))->toBe(1)
        ->and(bccomp((string) $rows['2026-02-04']->residual_unit_value, (string) $rows['2026-02-04']->updated_unit_value, 8))->toBe(-1);
});

it('uses the current vigent quantity for total value', function () {
    $emission = makePrefixedEmission();

    app(GeneratePuDailyCurve::class)->handle($emission, syncLegacyProjections: false);

    $firstRow = $emission->fresh()->puDailyCurves()->orderBy('curve_date')->first();

    // total_value = residual * quantidade (10)
    expect((string) $firstRow->quantity)->toBe('10.0000')
        ->and(bccomp((string) $firstRow->total_value, bcmul((string) $firstRow->residual_unit_value, '10', 8), 6))->toBe(0);
});
