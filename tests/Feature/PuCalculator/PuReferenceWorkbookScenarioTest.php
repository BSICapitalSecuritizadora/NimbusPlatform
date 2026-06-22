<?php

use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Services\PuReferenceWorkbookScenarioService;
use App\Domain\PuCalculator\Services\PuValidationSpreadsheetLocatorService;
use App\Models\Emission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('synchronizes the AMANI workbook scenario with lagged business-day CDI lookup', function () {
    $emission = Emission::factory()->create([
        'name' => 'CRI Amani',
        'type' => 'CRI',
        'status' => 'active',
        'issued_quantity' => 20000,
    ]);

    $spreadsheetPath = app(PuValidationSpreadsheetLocatorService::class)->findByKeyword('AMANI');
    $summary = app(PuReferenceWorkbookScenarioService::class)->sync($emission, $spreadsheetPath);

    $parameter = $emission->fresh()->puParameter;

    expect($summary['row_count'])->toBe(1810)
        ->and($summary['spread_rate'])->toBe('6.50000000')
        ->and($summary['index_lookup_mode'])->toBe(PuIndexRateLookupMode::BusinessDayLagExact->value)
        ->and($summary['index_lag_business_days'])->toBe(5)
        ->and($parameter)->not()->toBeNull()
        ->and($parameter?->index_rate_lookup_mode_enum)->toBe(PuIndexRateLookupMode::BusinessDayLagExact)
        ->and($parameter?->index_rate_lag_business_days)->toBe(5)
        ->and($emission->puEvents()->count())->toBe(61)
        ->and($emission->integralizationHistories()->count())->toBe(3);
});

it('synchronizes the TROUPE workbook scenario with exact previous-calendar-day CDI lookup', function () {
    $emission = Emission::factory()->create([
        'name' => 'CR Troupe',
        'type' => 'CR',
        'status' => 'active',
        'issued_quantity' => 10000,
    ]);

    $spreadsheetPath = app(PuValidationSpreadsheetLocatorService::class)->findByKeyword('TROUPE');
    $summary = app(PuReferenceWorkbookScenarioService::class)->sync($emission, $spreadsheetPath);

    $parameter = $emission->fresh()->puParameter;
    $precisionSensitiveAmortizationEvent = $emission->fresh()
        ->puEvents()
        ->where('event_type', 'amortization')
        ->whereDate('effective_date', '2025-07-30')
        ->first();

    expect($summary['row_count'])->toBe(696)
        ->and($summary['spread_rate'])->toBe('6.50000000')
        ->and($summary['index_lookup_mode'])->toBe(PuIndexRateLookupMode::PreviousCalendarDayExact->value)
        ->and($parameter)->not()->toBeNull()
        ->and($parameter?->index_rate_lookup_mode_enum)->toBe(PuIndexRateLookupMode::PreviousCalendarDayExact)
        ->and($parameter?->index_rate_lag_business_days)->toBe(1)
        ->and($emission->puEvents()->count())->toBe(46)
        ->and($emission->integralizationHistories()->count())->toBe(1)
        ->and($precisionSensitiveAmortizationEvent?->amortization_value)->toBe('43.4787075100000000');
});
