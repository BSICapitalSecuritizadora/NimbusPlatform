<?php

use App\Enums\ContractStatus;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\Emission;
use App\Services\Reports\EmissionMonthlyReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('PDF scenario A: several sales and several distratos', function () {
    $emission = Emission::factory()->withContractNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create(['development_name' => 'Residencial Aurora']);
    $units = ConstructionUnit::factory()->forConstruction($construction)->count(6)->create();

    Contract::factory()->forUnit($units[0])->create(['code' => 'A-001', 'sale_date' => '2026-07-02']);
    Contract::factory()->forUnit($units[1])->create(['code' => 'A-002', 'sale_date' => '2026-07-05']);
    Contract::factory()->forUnit($units[2])->create(['code' => 'A-003', 'sale_date' => '2026-07-10']);
    Contract::factory()->forUnit(ConstructionUnit::factory()->forConstruction($construction)->create())->create(['code' => 'D-001', 'sale_date' => '2026-05-01', 'cancellation_date' => '2026-07-03', 'status' => ContractStatus::Cancelled]);
    Contract::factory()->forUnit(ConstructionUnit::factory()->forConstruction($construction)->create())->create(['code' => 'D-002', 'sale_date' => '2026-04-01', 'cancellation_date' => '2026-07-15', 'status' => ContractStatus::Cancelled]);
    Contract::factory()->forUnit(ConstructionUnit::factory()->forConstruction($construction)->create())->create(['code' => 'D-003', 'sale_date' => '2026-03-01', 'cancellation_date' => '2026-07-20', 'status' => ContractStatus::Cancelled]);

    $data = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-07-01'));
    $html = view('pdf.emission-monthly-report', $data)->render();

    expect($html)->toContain('Vendas no período')
        ->and($html)->toContain('Distratos no período')
        ->and($html)->toContain('A-001')
        ->and($html)->toContain('D-001')
        ->and($html)->toContain('02/07/2026')
        ->and($html)->toContain('Saldo líquido de unidades')
        ->and($html)->toContain('Fonte: contratos');
});

it('PDF scenario B: only sales', function () {
    $emission = Emission::factory()->withContractNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create();
    Contract::factory()->forUnit($unit)->create(['code' => 'ONLY-SALES', 'sale_date' => '2026-07-10']);

    $data = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-07-01'));
    $html = view('pdf.emission-monthly-report', $data)->render();

    expect($html)->toContain('Vendas no período')
        ->and($html)->not->toContain('Distratos no período')
        ->and($html)->toContain('ONLY-SALES');
});

it('PDF scenario C: only distratos', function () {
    $emission = Emission::factory()->withContractNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create();
    Contract::factory()->forUnit($unit)->create(['code' => 'ONLY-DISTR', 'sale_date' => '2026-05-01', 'cancellation_date' => '2026-07-12', 'status' => ContractStatus::Cancelled]);

    $data = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-07-01'));
    $html = view('pdf.emission-monthly-report', $data)->render();

    expect($html)->toContain('Distratos no período')
        ->and($html)->not->toContain('Vendas no período')
        ->and($html)->toContain('ONLY-DISTR');
});

it('PDF scenario D: no transactions', function () {
    $emission = Emission::factory()->withContractNegotiations()->create();

    $data = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-07-01'));
    $html = view('pdf.emission-monthly-report', $data)->render();

    expect($html)->toContain('Não houve negociações no período.')
        ->and($html)->not->toContain('Vendas no período')
        ->and($html)->not->toContain('Distratos no período');
});

it('PDF scenario E: long contract codes and development names', function () {
    $emission = Emission::factory()->withContractNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create(['development_name' => 'Residencial Aurora Torre Premium Long Name With Many Words And Details For Testing']);
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => 'Torre A Bloco Premium Longo', 'unit' => 'Unidade 1001 Bloco Teste Longo']);
    Contract::factory()->forUnit($unit)->create(['code' => 'VERY-LONG-CONTRACT-CODE-1234567890-ABCDEFGHIJ', 'sale_date' => '2026-07-15']);

    $data = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-07-01'));
    $html = view('pdf.emission-monthly-report', $data)->render();

    expect($html)->toContain('VERY-LONG-CONTRACT-CODE-1234567890-ABCDEFGHIJ')
        ->and($html)->toContain('Residencial Aurora Torre Premium')
        ->and($html)->toContain('Bloco Torre A Bloco Premium Longo — Unidade Unidade 1001 Bloco Teste Longo')
        ->and($html)->toContain('15/07/2026');
});

it('PDF scenario F: many transactions approaching page boundary', function () {
    $emission = Emission::factory()->withContractNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();
    for ($i = 1; $i <= 30; $i++) {
        $unit = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => 'B'.$i, 'unit' => (string) (100 + $i)]);
        Contract::factory()->forUnit($unit)->create(['code' => sprintf('PAGE-%03d', $i), 'sale_date' => '2026-07-'.str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT)]);
    }

    $data = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-07-01'));
    $html = view('pdf.emission-monthly-report', $data)->render();

    expect($html)->toContain('Vendas no período')
        ->and($html)->toContain('PAGE-001')
        ->and($html)->toContain('PAGE-030')
        ->and($html)->toContain('page-break-inside: avoid')
        ->and($html)->toContain('word-break: break-word');
});
