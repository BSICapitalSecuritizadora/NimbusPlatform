<?php

use App\Actions\Emissions\GeneratePuDailyCurve;
use App\Actions\Emissions\ValidatePuDailyCurve;
use App\Domain\PuCalculator\Enums\PuValidationMode;
use App\Domain\PuCalculator\Services\PuReferenceWorkbookScenarioService;
use App\Domain\PuCalculator\Services\PuSpreadsheetReferenceReader;
use App\Domain\PuCalculator\Services\PuValidationSpreadsheetLocatorService;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Models\BusinessCalendarDate;
use App\Models\Emission;
use App\Models\EmissionPuDailyCurve;
use App\Models\IndexRate;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('blocks PU generation from the admin screen when prerequisites are incomplete', function () {
    Queue::fake();
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create([
        'type' => 'CRI',
        'status' => 'active',
    ]);

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
        'legacy_projection_enabled' => true,
    ]);

    Livewire::test(EditEmission::class, [
        'record' => $emission->getRouteKey(),
    ])
        ->callAction('generatePuDailyCurve')
        ->assertHasNoActionErrors();

    Queue::assertNothingPushed();
    expect(Cache::get("pu_curve_generation_{$emission->id}_status"))->toBeNull();
});

it('exports the generated PU curve as CSV through the admin route', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create([
        'type' => 'CRI',
        'status' => 'active',
    ]);

    EmissionPuDailyCurve::factory()->create([
        'emission_id' => $emission->id,
        'curve_date' => '2026-01-02',
        'calculation_version' => 'v7',
        'updated_unit_value' => '1001.2500000000000000',
        'residual_unit_value' => '1000.9000000000000000',
        'total_value' => '100090.0000000000000000',
        'payment_total_value' => '0.0000000000000000',
        'interest_payment_value' => '0.0000000000000000',
        'index_rate_value' => '13.65000000',
        'dup_interest' => 1,
        'dut_interest' => 252,
    ]);

    $response = $this->get(route('admin.emissions.pu-curves.export', [
        'emission' => $emission,
        'calculation_version' => 'v7',
    ]));

    $response->assertSuccessful();

    expect($response->streamedContent())->toContain('versao_calculo')
        ->toContain('2026-01-02')
        ->toContain('v7');
});

it('stores calculation memory for each persisted curve row', function () {
    $emission = Emission::factory()->create([
        'type' => 'CRI',
        'status' => 'active',
        'issued_quantity' => 1000,
    ]);

    seedOperationalCalendar('2026-01-01', '2026-01-03');
    seedOperationalRates('2026-01-01', '2026-01-03', '13.65000000');

    $emission->integralizationHistories()->create([
        'date' => '2026-01-01',
        'quantity' => '10.0000',
        'unit_value' => '1000.00000000',
        'financial_value' => '10000.00',
        'investor_fund' => 'Head Invest',
    ]);

    $emission->puParameter()->create([
        'curve_start_date' => '2026-01-01',
        'curve_end_date' => '2026-01-03',
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '6.50000000',
        'indexer' => 'CDI',
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'legacy_projection_enabled' => false,
    ]);

    app(GeneratePuDailyCurve::class)->handle($emission, syncLegacyProjections: false);

    $curveRow = $emission->fresh()->puDailyCurves()->firstOrFail();

    expect($curveRow->calculation_memory)->toBeArray()
        ->and($curveRow->calculation_memory)->toHaveKeys([
            'engine_version',
            'base_unit_value_raw',
            'factor_di_raw',
            'factor_spread_di_raw',
            'quantity_raw',
            'reset_after_payment',
        ]);
});

it('logs validation runs and supports complete AMANI and TROUPE workflows', function (string $keyword, int $expectedRows) {
    $user = makeAdminUser();
    $this->actingAs($user);

    $emission = Emission::factory()->create([
        'name' => $keyword.' Reference',
        'type' => $keyword === 'AMANI' ? 'CRI' : 'CR',
        'status' => 'active',
        'issued_quantity' => 20000,
    ]);

    $spreadsheetPath = app(PuValidationSpreadsheetLocatorService::class)->findByKeyword($keyword);
    app(PuReferenceWorkbookScenarioService::class)->sync($emission, $spreadsheetPath);
    $generationResult = app(GeneratePuDailyCurve::class)->handle($emission, syncLegacyProjections: false);

    $displayReport = app(ValidatePuDailyCurve::class)->handle(
        $emission,
        $spreadsheetPath,
        $generationResult->calculationVersion,
        PuValidationMode::DisplayScale,
        null,
        null,
        $user->id,
    );

    $rawReport = app(ValidatePuDailyCurve::class)->handle(
        $emission,
        $spreadsheetPath,
        $generationResult->calculationVersion,
        PuValidationMode::RawScale,
    );

    expect($displayReport->totalRowsCompared)->toBe($expectedRows)
        ->and($rawReport->totalRowsCompared)->toBe($expectedRows)
        ->and($displayReport->calculationVersion)->toBe($generationResult->calculationVersion)
        ->and(Activity::query()
            ->where('log_name', 'pu-calculation')
            ->where('description', 'pu_curve_validated')
            ->where('subject_id', $emission->id)
            ->exists())->toBeTrue();
})->with([
    'AMANI' => ['AMANI', 1810],
    'TROUPE' => ['TROUPE', 696],
]);

it('logs approved validation payloads with severity-aware sample data', function () {
    $user = makeAdminUser();
    $emission = Emission::factory()->create([
        'type' => 'CRI',
        'status' => 'active',
    ]);

    $spreadsheetPath = app(PuValidationSpreadsheetLocatorService::class)->findByKeyword('AMANI');
    $referenceRows = app(PuSpreadsheetReferenceReader::class)->read($spreadsheetPath)['rows'];

    persistOperationalReferenceRows($emission, $referenceRows, 'v1');

    $report = app(ValidatePuDailyCurve::class)->handle(
        $emission,
        $spreadsheetPath,
        'v1',
        PuValidationMode::DisplayScale,
        null,
        null,
        $user->id,
    );

    $activity = Activity::query()
        ->where('log_name', 'pu-calculation')
        ->where('description', 'pu_curve_validated')
        ->where('subject_id', $emission->id)
        ->latest('id')
        ->first();

    expect($report->totalDivergences)->toBe(0)
        ->and($activity)->not()->toBeNull()
        ->and($activity?->properties['mode'])->toBe('display-scale')
        ->and($activity?->properties['status'])->toBe('approved')
        ->and($activity?->properties['sample_differences'])->toBeArray();
});

function seedOperationalCalendar(string $startDate, string $endDate): void
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

function seedOperationalRates(string $startDate, string $endDate, string $rateValue): void
{
    for ($date = \Carbon\CarbonImmutable::parse($startDate); $date->lte(\Carbon\CarbonImmutable::parse($endDate)); $date = $date->addDay()) {
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

function persistOperationalReferenceRows(Emission $emission, array $referenceRows, string $calculationVersion): void
{
    $timestamp = now();
    $rows = array_map(function ($row) use ($emission, $timestamp, $calculationVersion): array {
        $quantity = $row->quantity ?? '0.0000';
        $interestPaymentUnitValue = $quantity !== '0.0000' && $row->paymentInterestTotal !== null
            ? bcdiv($row->paymentInterestTotal, $quantity, 16)
            : '0.0000000000000000';
        $paymentTotalUnitValue = $quantity !== '0.0000' && $row->paymentTotalValue !== null
            ? bcdiv($row->paymentTotalValue, $quantity, 16)
            : '0.0000000000000000';

        return [
            'emission_id' => $emission->id,
            'curve_date' => $row->date->toDateString(),
            'calculation_version' => $calculationVersion,
            'is_business_day' => true,
            'unit_base_value' => $row->unitBaseValue ?? $row->residualUnitValue ?? '0.0000000000000000',
            'unit_corrected_value' => $row->correctedUnitValue ?? $row->residualUnitValue ?? '0.0000000000000000',
            'factor_di' => $row->factorDi ?? '1.0000000000000000',
            'factor_di_accumulated' => $row->factorDiAccumulated ?? '1.0000000000000000',
            'factor_spread' => $row->factorSpread ?? '1.0000000000000000',
            'factor_spread_di' => $row->factorSpreadDi ?? '1.0000000000000000',
            'interest_real_unit_value' => $row->interestRealUnitValue ?? '0.0000000000000000',
            'updated_unit_value' => $row->updatedUnitValue ?? '0.0000000000000000',
            'amortization_ratio' => '0.0000000000000000',
            'amortization_unit_value' => $row->amortizationUnitValue ?? '0.0000000000000000',
            'amortization_value' => bcmul($row->amortizationUnitValue ?? '0.0000000000000000', $quantity, 16),
            'residual_unit_value' => $row->residualUnitValue ?? '0.0000000000000000',
            'quantity' => $quantity,
            'total_value' => $row->totalValue ?? '0.0000000000000000',
            'interest_payment_unit_value' => $interestPaymentUnitValue,
            'interest_payment_value' => $row->paymentInterestTotal ?? '0.0000000000000000',
            'payment_total_unit_value' => $paymentTotalUnitValue,
            'payment_total_value' => $row->paymentTotalValue ?? '0.0000000000000000',
            'dup_correction' => $row->dupCorrection,
            'dut_correction' => $row->dutCorrection,
            'dup_interest' => $row->dupInterest,
            'dut_interest' => $row->dutInterest,
            'index_rate_date' => $row->indexRateDate?->toDateString(),
            'index_rate_value' => $row->indexRateValue,
            'event_original_date' => $row->eventOriginalDate?->toDateString(),
            'event_effective_date' => $row->eventDueDate?->toDateString(),
            'calculation_memory' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }, $referenceRows);

    foreach (array_chunk($rows, 500) as $chunk) {
        EmissionPuDailyCurve::query()->insert($chunk);
    }
}
