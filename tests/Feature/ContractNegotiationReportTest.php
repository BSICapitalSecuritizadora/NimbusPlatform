<?php

use App\Enums\ContractStatus;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\Emission;
use App\Services\Reports\EmissionMonthlyReportService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    // No extra setup needed
});

it('shows sale inside reference month as Venda', function () {
    $emission = Emission::factory()->withContractNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create(['development_name' => 'Residencial Aurora']);
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => 'Torre A', 'unit' => '101']);

    Contract::factory()->forUnit($unit)->create([
        'code' => 'ABC-123',
        'sale_date' => '2026-07-15',
        'cancellation_date' => null,
        'status' => ContractStatus::Active,
    ]);

    $data = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-07-01'));

    expect($data['negotiations']['has_data'])->toBeTrue()
        ->and($data['negotiations']['sales_count'])->toBe(1)
        ->and($data['negotiations']['vendas'])->toHaveCount(1)
        ->and($data['negotiations']['vendas'][0]['code'])->toBe('ABC-123')
        ->and($data['negotiations']['vendas'][0]['type'])->toBe('Venda')
        ->and($data['negotiations']['vendas'][0]['date_formatted'])->toBe('15/07/2026')
        ->and($data['negotiations']['vendas'][0]['block'])->toBe('Torre A')
        ->and($data['negotiations']['vendas'][0]['unit'])->toBe('101')
        ->and($data['negotiations']['vendas'][0]['development'])->toBe('Residencial Aurora');
});

it('does not show sale outside reference month', function () {
    $emission = Emission::factory()->withContractNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create();

    Contract::factory()->forUnit($unit)->create([
        'code' => 'OUT-001',
        'sale_date' => '2026-06-30',
        'cancellation_date' => null,
    ]);

    $data = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-07-01'));

    expect($data['negotiations']['has_data'])->toBeFalse()
        ->and($data['negotiations']['sales_count'])->toBe(0)
        ->and($data['negotiations']['empty_message'])->toBe('Não houve negociações no período.');
});

it('shows distrato inside reference month as Distrato', function () {
    $emission = Emission::factory()->withContractNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create(['development_name' => 'Torre B']);
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => 'Torre B', 'unit' => '305']);

    Contract::factory()->forUnit($unit)->create([
        'code' => 'XYZ-987',
        'sale_date' => '2025-11-10',
        'cancellation_date' => '2026-07-22',
        'status' => ContractStatus::Cancelled,
    ]);

    $data = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-07-01'));

    expect($data['negotiations']['has_data'])->toBeTrue()
        ->and($data['negotiations']['cancellations_count'])->toBe(1)
        ->and($data['negotiations']['distratos'])->toHaveCount(1)
        ->and($data['negotiations']['distratos'][0]['code'])->toBe('XYZ-987')
        ->and($data['negotiations']['distratos'][0]['type'])->toBe('Distrato')
        ->and($data['negotiations']['distratos'][0]['date_formatted'])->toBe('22/07/2026');
});

it('does not show distrato outside reference month', function () {
    $emission = Emission::factory()->withContractNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create();

    Contract::factory()->forUnit($unit)->create([
        'code' => 'OUT-DISTR',
        'sale_date' => '2025-11-10',
        'cancellation_date' => '2026-08-01',
        'status' => ContractStatus::Cancelled,
    ]);

    $data = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-07-01'));

    expect($data['negotiations']['has_data'])->toBeFalse()
        ->and($data['negotiations']['cancellations_count'])->toBe(0);
});

it('contract sold in one month and terminated in another appears in correct month for each event', function () {
    $emission = Emission::factory()->withContractNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create();

    Contract::factory()->forUnit($unit)->create([
        'code' => 'MIX-001',
        'sale_date' => '2026-03-15',
        'cancellation_date' => '2026-07-10',
        'status' => ContractStatus::Cancelled,
    ]);

    $march = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-03-01'));
    $july = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-07-01'));

    expect($march['negotiations']['vendas'])->toHaveCount(1)
        ->and($march['negotiations']['distratos'])->toHaveCount(0)
        ->and($july['negotiations']['vendas'])->toHaveCount(0)
        ->and($july['negotiations']['distratos'])->toHaveCount(1)
        ->and($march['negotiations']['vendas'][0]['code'])->toBe('MIX-001')
        ->and($july['negotiations']['distratos'][0]['code'])->toBe('MIX-001');
});

it('contract without cancellation date never appears as Distrato', function () {
    $emission = Emission::factory()->withContractNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create();

    Contract::factory()->forUnit($unit)->create([
        'code' => 'NO-DISTR',
        'sale_date' => '2026-07-10',
        'cancellation_date' => null,
        'status' => ContractStatus::Active,
    ]);

    $data = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-07-01'));

    expect($data['negotiations']['cancellations_count'])->toBe(0)
        ->and($data['negotiations']['sales_count'])->toBe(1);
});

it('excludes contracts from another emission', function () {
    $emissionA = Emission::factory()->withContractNegotiations()->create();
    $emissionB = Emission::factory()->withContractNegotiations()->create();

    $constructionA = Construction::factory()->for($emissionA)->create();
    $unitA = ConstructionUnit::factory()->forConstruction($constructionA)->create();
    $constructionB = Construction::factory()->for($emissionB)->create();
    $unitB = ConstructionUnit::factory()->forConstruction($constructionB)->create();

    Contract::factory()->forUnit($unitA)->create(['code' => 'A-001', 'sale_date' => '2026-07-10']);
    Contract::factory()->forUnit($unitB)->create(['code' => 'B-001', 'sale_date' => '2026-07-10']);

    $dataA = app(EmissionMonthlyReportService::class)->build($emissionA, CarbonImmutable::parse('2026-07-01'));

    expect($dataA['negotiations']['vendas'])->toHaveCount(1)
        ->and($dataA['negotiations']['vendas'][0]['code'])->toBe('A-001');
});

it('returns multiple transactions during same month', function () {
    $emission = Emission::factory()->withContractNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();
    $units = ConstructionUnit::factory()->forConstruction($construction)->count(3)->create();

    Contract::factory()->forUnit($units[0])->create(['code' => 'MULTI-1', 'sale_date' => '2026-07-05']);
    Contract::factory()->forUnit($units[1])->create(['code' => 'MULTI-2', 'sale_date' => '2026-07-10', 'cancellation_date' => '2026-07-20', 'status' => ContractStatus::Cancelled]);
    // This contract's sale is outside, but distrato inside
    $unitExtra = ConstructionUnit::factory()->forConstruction($construction)->create();
    Contract::factory()->forUnit($unitExtra)->create(['code' => 'MULTI-3', 'sale_date' => '2026-05-01', 'cancellation_date' => '2026-07-15', 'status' => ContractStatus::Cancelled]);

    $data = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-07-01'));

    expect($data['negotiations']['sales_count'])->toBe(2) // MULTI-1 and MULTI-2 sales
        ->and($data['negotiations']['cancellations_count'])->toBe(2) // MULTI-2 distrato + MULTI-3 distrato
        ->and($data['negotiations']['vendas'])->toHaveCount(2)
        ->and($data['negotiations']['distratos'])->toHaveCount(2);
});

it('shows proper empty state when no negotiations in month', function () {
    $emission = Emission::factory()->withContractNegotiations()->create();

    $data = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-07-01'));
    $html = view('pdf.emission-monthly-report', $data)->render();

    expect($data['negotiations']['has_data'])->toBeFalse()
        ->and($data['negotiations']['empty_message'])->toBe('Não houve negociações no período.')
        ->and($html)->toContain('Não houve negociações no período.')
        ->and($html)->not->toContain('Vendas no período')
        ->and($html)->not->toContain('Distratos no período');
});

it('displays correct block unit and contract code and orders chronologically', function () {
    $emission = Emission::factory()->withContractNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create(['development_name' => 'Torre A']);
    $unit101 = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => 'A', 'unit' => '101']);
    $unit102 = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => 'A', 'unit' => '102']);
    $unit201 = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => 'B', 'unit' => '201']);

    Contract::factory()->forUnit($unit102)->create(['code' => 'CODE-102', 'sale_date' => '2026-07-20']);
    Contract::factory()->forUnit($unit101)->create(['code' => 'CODE-101', 'sale_date' => '2026-07-05']);
    Contract::factory()->forUnit($unit201)->create(['code' => 'CODE-201', 'sale_date' => '2026-06-01', 'cancellation_date' => '2026-07-15', 'status' => ContractStatus::Cancelled]);
    Contract::factory()->forUnit(ConstructionUnit::factory()->forConstruction($construction)->create(['block' => 'B', 'unit' => '202']))->create(['code' => 'CODE-202', 'sale_date' => '2026-04-01', 'cancellation_date' => '2026-07-02', 'status' => ContractStatus::Cancelled]);

    $data = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-07-01'));

    expect($data['negotiations']['vendas'][0]['code'])->toBe('CODE-101') // earliest sale first
        ->and($data['negotiations']['vendas'][1]['code'])->toBe('CODE-102')
        ->and($data['negotiations']['distratos'][0]['code'])->toBe('CODE-202') // 02/07 before 15/07
        ->and($data['negotiations']['distratos'][1]['code'])->toBe('CODE-201')
        ->and($data['negotiations']['vendas'][0]['display'])->toBe('Bloco A — Unidade 101')
        ->and($data['negotiations']['vendas'][0]['block'])->toBe('A')
        ->and($data['negotiations']['vendas'][0]['unit'])->toBe('101');

    $html = view('pdf.emission-monthly-report', $data)->render();
    expect($html)->toContain('Bloco A — Unidade 101')
        ->and($html)->toContain('CODE-101')
        ->and($html)->toContain('05/07/2026');
});

it('uses sale_date not created_at', function () {
    $emission = Emission::factory()->withContractNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create();

    // Create contract in July but sale_date in June — should not appear in July
    $contract = Contract::factory()->forUnit($unit)->create([
        'code' => 'DATE-TEST',
        'sale_date' => '2026-06-15',
        'created_at' => '2026-07-10 10:00:00',
    ]);
    // Force created_at to July
    DB::table('contracts')->where('id', $contract->id)->update(['created_at' => '2026-07-10 10:00:00']);

    $july = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-07-01'));
    $june = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-06-01'));

    expect($july['negotiations']['sales_count'])->toBe(0)
        ->and($june['negotiations']['sales_count'])->toBe(1);
});

it('does not base distrato on status alone', function () {
    $emission = Emission::factory()->withContractNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create();

    // Status cancelled but cancellation_date outside month — should not appear
    Contract::factory()->forUnit($unit)->create([
        'code' => 'STATUS-ONLY',
        'sale_date' => '2026-06-01',
        'cancellation_date' => '2026-06-20',
        'status' => ContractStatus::Cancelled,
    ]);

    $july = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-07-01'));

    expect($july['negotiations']['cancellations_count'])->toBe(0);
});

it('is historically consistent regardless of current date', function () {
    $emission = Emission::factory()->withContractNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create();

    Contract::factory()->forUnit($unit)->create([
        'code' => 'HIST-001',
        'sale_date' => '2026-07-15',
    ]);

    // Generate July report in December — should still be same
    $julyFromDecember = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-07-01'));
    // Mock now to December
    Carbon::setTestNow(CarbonImmutable::parse('2026-12-15'));

    $julyAgain = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-07-01'));

    Carbon::setTestNow();

    expect($julyFromDecember['negotiations']['sales_count'])->toBe(1)
        ->and($julyAgain['negotiations']['sales_count'])->toBe(1);
});

it('includes boundary dates inclusive', function () {
    $emission = Emission::factory()->withContractNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();
    $unit1 = ConstructionUnit::factory()->forConstruction($construction)->create();
    $unit2 = ConstructionUnit::factory()->forConstruction($construction)->create();

    Contract::factory()->forUnit($unit1)->create(['code' => 'B-START', 'sale_date' => '2026-07-01']);
    Contract::factory()->forUnit($unit2)->create(['code' => 'B-END', 'sale_date' => '2026-07-31']);

    $data = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-07-01'));

    expect($data['negotiations']['sales_count'])->toBe(2);
});
