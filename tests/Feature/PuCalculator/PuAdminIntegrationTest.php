<?php

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Enums\PuValidationMode;
use App\Domain\PuCalculator\Services\PuSpreadsheetReferenceReader;
use App\Domain\PuCalculator\Services\PuValidationSpreadsheetLocatorService;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PuDailyCurvesRelationManager;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PuEventsRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Jobs\GeneratePuDailyCurveJob;
use App\Models\BusinessCalendarDate;
use App\Models\BusinessCalendarSelectionEvidence;
use App\Models\Emission;
use App\Models\EmissionPuDailyCurve;
use App\Models\IndexRate;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('shows the PU actions and relation managers on the emission admin screen', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create();

    Livewire::test(EditEmission::class, [
        'record' => $emission->getRouteKey(),
    ])
        ->assertActionExists('configurePuCalculation')
        ->assertActionExists('generatePuDailyCurve')
        ->assertActionExists('viewPuDailyCurve')
        ->assertActionExists('validatePuDailyCurve')
        ->assertActionExists('viewPuValidationReport')
        ->assertActionExists('exportPuDailyCurve');

    Livewire::test(PuEventsRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])->assertTableHeaderActionsExistInOrder(['create']);

    Livewire::test(PuDailyCurvesRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])->assertSuccessful();
});

it('accepts a negative business day lag on the PU calculation parameters form', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create();

    Livewire::test(EditEmission::class, [
        'record' => $emission->getRouteKey(),
    ])
        ->callAction('configurePuCalculation', [
            'curve_start_date' => '2026-01-01',
            'curve_end_date' => '2026-01-31',
            'initial_unit_value' => '1000.00',
            'indexer' => PuIndexer::Cdi->value,
            'spread_rate' => '6.50',
            'business_day_basis' => 252,
            'calendar_code' => BusinessCalendarRegistry::BR_BANKING_ANBIMA,
            'index_rate_lookup_mode' => PuIndexRateLookupMode::BusinessDayLagExact->value,
            'index_rate_lag_business_days' => -5,
            'legacy_projection_enabled' => true,
        ])
        ->assertHasNoActionErrors();

    expect($emission->fresh()->puParameter?->index_rate_lag_business_days)->toBe(-5);
});

it('requires an explicit calendar for a new PU configuration', function () {
    $this->actingAs(makeAdminUser());
    $emission = Emission::factory()->create();

    Livewire::test(EditEmission::class, ['record' => $emission->getRouteKey()])
        ->callAction('configurePuCalculation', [
            'curve_start_date' => '2026-01-01',
            'curve_end_date' => '2026-01-31',
            'initial_unit_value' => '1000.00',
            'indexer' => PuIndexer::Cdi->value,
            'spread_rate' => '6.50',
            'business_day_basis' => 252,
            'index_rate_lookup_mode' => PuIndexRateLookupMode::PreviousAvailableBusinessDay->value,
            'legacy_projection_enabled' => true,
        ])
        ->assertHasActionErrors(['calendar_code' => 'required']);

    expect($emission->fresh()->puParameter)->toBeNull();
});

it('keeps B3 available when editing an existing legacy PU configuration', function () {
    $this->actingAs(makeAdminUser());
    $emission = Emission::factory()->create();
    $emission->puParameter()->create([
        'curve_start_date' => '2026-01-01',
        'curve_end_date' => '2026-01-31',
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '6.50000000',
        'indexer' => PuIndexer::Cdi->value,
        'business_day_basis' => 252,
        'calendar_code' => BusinessCalendarRegistry::LEGACY_B3,
        'index_rate_lookup_mode' => PuIndexRateLookupMode::PreviousAvailableBusinessDay->value,
        'legacy_projection_enabled' => true,
    ]);

    Livewire::test(EditEmission::class, ['record' => $emission->getRouteKey()])
        ->callAction('configurePuCalculation', [
            'curve_start_date' => '2026-01-01',
            'curve_end_date' => '2026-01-31',
            'initial_unit_value' => '1000.00',
            'indexer' => PuIndexer::Cdi->value,
            'spread_rate' => '6.50',
            'business_day_basis' => 252,
            'calendar_code' => BusinessCalendarRegistry::LEGACY_B3,
            'index_rate_lookup_mode' => PuIndexRateLookupMode::PreviousAvailableBusinessDay->value,
            'legacy_projection_enabled' => true,
        ])
        ->assertHasNoActionErrors();

    expect($emission->fresh()->puParameter?->calendar_code)->toBe(BusinessCalendarRegistry::LEGACY_B3);
});

it('records the optional evidence when the administrator confirms a new calendar choice', function () {
    $admin = makeAdminUser();
    $this->actingAs($admin);
    $emission = Emission::factory()->create();

    Livewire::test(EditEmission::class, ['record' => $emission->getRouteKey()])
        ->callAction('configurePuCalculation', [
            'curve_start_date' => '2026-01-01',
            'curve_end_date' => '2026-01-31',
            'initial_unit_value' => '1000.00',
            'indexer' => PuIndexer::Cdi->value,
            'spread_rate' => '6.50',
            'business_day_basis' => 252,
            'calendar_code' => BusinessCalendarRegistry::BR_BANKING_ANBIMA,
            'calendar_evidence_document' => 'Termo de Securitização',
            'calendar_evidence_clause' => 'Cláusula 5.1',
            'calendar_evidence_page' => '42',
            'calendar_evidence_excerpt' => 'Calendário bancário aplicável.',
            'calendar_evidence_confirmed' => true,
            'index_rate_lookup_mode' => PuIndexRateLookupMode::PreviousAvailableBusinessDay->value,
            'legacy_projection_enabled' => true,
        ])
        ->assertHasNoActionErrors();

    $evidence = BusinessCalendarSelectionEvidence::query()->sole();

    expect($evidence->calendar_code)->toBe(BusinessCalendarRegistry::BR_BANKING_ANBIMA)
        ->and($evidence->source_document)->toBe('Termo de Securitização')
        ->and($evidence->confirmed_by)->toBe($admin->id)
        ->and($evidence->confirmed_at)->not->toBeNull();
});

it('stores PU calculation parameters on the emission model using the same payload expected by the page action', function () {
    $emission = Emission::factory()->create([
        'issue_date' => '2026-01-01',
        'maturity_date' => '2026-12-31',
        'issued_price' => '1000.00',
        'remuneration_indexer' => 'CDI',
        'remuneration_rate' => '6.50',
    ]);

    $emission->puParameter()->updateOrCreate([], [
        'curve_start_date' => '2026-01-01',
        'curve_end_date' => '2026-01-31',
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '6.50000000',
        'indexer' => 'CDI',
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'index_rate_lookup_mode' => PuIndexRateLookupMode::PreviousAvailableBusinessDay->value,
        'index_rate_lag_business_days' => 1,
        'legacy_projection_enabled' => true,
    ]);

    expect($emission->fresh()->puParameter)->not()->toBeNull()
        ->and(bccomp((string) $emission->fresh()->puParameter?->spread_rate, '6.50000000', 8))->toBe(0);
});

it('dispatches the PU curve generation job from the emission page action', function () {
    Queue::fake();

    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create([
        'type' => 'CRI',
        'status' => 'active',
    ]);

    seedBusinessCalendarForAdmin('2026-02-02', '2026-02-05');
    seedFixedCdiRatesForAdmin('2026-02-02', '2026-02-05', '14.90000000');

    $emission->integralizationHistories()->create([
        'date' => '2026-02-02',
        'quantity' => '100.0000',
        'unit_value' => '1000.00000000',
        'financial_value' => '100000.00',
        'investor_fund' => 'Head Invest',
    ]);

    $emission->puParameter()->create([
        'curve_start_date' => '2026-02-02',
        'curve_end_date' => '2026-02-05',
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '6.50000000',
        'indexer' => 'CDI',
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'index_rate_lookup_mode' => PuIndexRateLookupMode::PreviousAvailableBusinessDay->value,
        'index_rate_lag_business_days' => 1,
        'legacy_projection_enabled' => true,
    ]);

    $emission->puEvents()->create([
        'event_type' => 'interest_payment',
        'original_date' => '2026-02-05',
        'effective_date' => '2026-02-05',
        'amortization_type' => 'none',
        'sequence' => 1,
    ]);

    Livewire::test(EditEmission::class, [
        'record' => $emission->getRouteKey(),
    ])
        ->callAction('generatePuDailyCurve')
        ->assertHasNoActionErrors();

    Queue::assertPushed(GeneratePuDailyCurveJob::class, fn (GeneratePuDailyCurveJob $job): bool => $job->emissionId === $emission->id);

    expect(Cache::get("pu_curve_generation_{$emission->id}_status"))->toBe('processing')
        ->and($emission->fresh()->puDailyCurves()->count())->toBe(0);
});

it('validates a selected spreadsheet version from the emission page action', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create([
        'type' => 'CR',
        'status' => 'active',
    ]);

    $spreadsheetPath = sampleSpreadsheetPathForAdmin('TROUPE');
    $referenceRows = app(PuSpreadsheetReferenceReader::class)->read($spreadsheetPath)['rows'];

    persistReferenceRowsForAdmin($emission, $referenceRows, 'v2');

    Livewire::test(EditEmission::class, [
        'record' => $emission->getRouteKey(),
    ])
        ->callAction('validatePuDailyCurve', data: [
            'reference_spreadsheet' => sampleSpreadsheetSelectionForAdmin('TROUPE'),
            'calculation_version' => 'v2',
            'validation_mode' => PuValidationMode::DisplayScale->value,
        ])
        ->assertHasNoActionErrors();
});

function seedBusinessCalendarForAdmin(string $startDate, string $endDate): void
{
    for ($date = CarbonImmutable::parse($startDate); $date->lte(CarbonImmutable::parse($endDate)); $date = $date->addDay()) {
        BusinessCalendarDate::query()->create([
            'calendar_code' => 'B3',
            'calendar_date' => $date->toDateString(),
            'is_business_day' => ! $date->isWeekend(),
            'description' => null,
        ]);
    }
}

function seedFixedCdiRatesForAdmin(string $startDate, string $endDate, string $rateValue): void
{
    for ($date = CarbonImmutable::parse($startDate); $date->lte(CarbonImmutable::parse($endDate)); $date = $date->addDay()) {
        if ($date->isWeekend()) {
            continue;
        }

        IndexRate::query()->create([
            'indexer' => 'CDI',
            'rate_date' => $date->toDateString(),
            'rate_value' => $rateValue,
            'source' => 'testing',
            'source_reference' => 'fixed-rate',
        ]);
    }
}

function sampleSpreadsheetPathForAdmin(string $keyword): string
{
    return puValidationSpreadsheetPath($keyword);
}

function sampleSpreadsheetSelectionForAdmin(string $keyword): string
{
    $path = sampleSpreadsheetPathForAdmin($keyword);
    $options = app(PuValidationSpreadsheetLocatorService::class)->options();

    return array_search(basename($path), $options, true) ?: array_key_first($options);
}

function persistReferenceRowsForAdmin(Emission $emission, array $referenceRows, string $calculationVersion): void
{
    $timestamp = now();
    $rows = array_map(function ($row) use ($emission, $timestamp, $calculationVersion): array {
        $amortizationUnitValue = $row->amortizationUnitValue ?? '0.0000000000000000';
        $quantity = $row->quantity ?? '0.0000';

        return [
            'emission_id' => $emission->id,
            'curve_date' => $row->date->toDateString(),
            'calculation_version' => $calculationVersion,
            'is_business_day' => true,
            'unit_base_value' => $row->residualUnitValue ?? $row->updatedUnitValue ?? '0.0000000000000000',
            'unit_corrected_value' => $row->residualUnitValue ?? $row->updatedUnitValue ?? '0.0000000000000000',
            'factor_di' => '1.0000000000000000',
            'factor_di_accumulated' => '1.0000000000000000',
            'factor_spread' => '1.0000000000000000',
            'factor_spread_di' => '1.0000000000000000',
            'interest_real_unit_value' => $row->interestRealUnitValue ?? '0.0000000000000000',
            'updated_unit_value' => $row->updatedUnitValue ?? '0.0000000000000000',
            'amortization_ratio' => '0.0000000000000000',
            'amortization_unit_value' => $amortizationUnitValue,
            'amortization_value' => bcmul($amortizationUnitValue, $quantity, 16),
            'residual_unit_value' => $row->residualUnitValue ?? '0.0000000000000000',
            'quantity' => $quantity,
            'total_value' => $row->totalValue ?? '0.0000000000000000',
            'interest_payment_unit_value' => '0.0000000000000000',
            'interest_payment_value' => '0.0000000000000000',
            'payment_total_unit_value' => '0.0000000000000000',
            'payment_total_value' => $row->paymentTotalValue ?? '0.0000000000000000',
            'dup_correction' => 0,
            'dut_correction' => 0,
            'dup_interest' => $row->dupInterest,
            'dut_interest' => $row->dutInterest,
            'index_rate_date' => $row->date->toDateString(),
            'index_rate_value' => $row->indexRateValue,
            'event_original_date' => null,
            'event_effective_date' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }, $referenceRows);

    foreach (array_chunk($rows, 500) as $chunk) {
        EmissionPuDailyCurve::query()->insert($chunk);
    }
}
