<?php

use App\Actions\Proposals\CalculateProjectIndicators;
use App\Enums\ProjectIndicatorClassification;
use App\Models\ProjectIndicator;
use App\Models\ProposalProject;

it('calculates all nine indicators with the corrected formulas', function () {
    $project = new ProposalProject([
        'requested_amount' => 70,
        'land_market_value' => 20,
        'land_area' => 10,
        'exchanged_units' => 10,
        'paid_units' => 20,
        'unpaid_units' => 10,
        'stock_units' => 60,
        'units_total' => 100,
        'sales_percentage' => 33.33,
        'incurred_cost' => 30,
        'cost_to_incur' => 70,
        'total_cost' => 100,
        'work_stage_percentage' => 30,
        'paid_sales_value' => 20,
        'unpaid_sales_value' => 80,
        'stock_sales_value' => 100,
        'gross_sales_value' => 200,
        'received_value' => 20,
        'value_until_keys' => 30,
        'value_after_keys' => 50,
    ]);
    $project->setRelation('indicators', new ProjectIndicator([
        'financiamento_custo_obra_ideal' => 70, 'financiamento_custo_obra_limite' => 90,
        'financiamento_vgv_ideal' => 65, 'financiamento_vgv_limite' => 75,
        'custo_obra_vgv_ideal' => 65, 'custo_obra_vgv_limite' => 75,
        'recebiveis_vfcto_ideal' => 100, 'recebiveis_vfcto_limite' => 95,
        'recebiveis_terreno_vfcto_ideal' => 100, 'recebiveis_terreno_vfcto_limite' => 95,
        'vendas_liquido_permutas_ideal' => 30, 'vendas_liquido_permutas_limite' => 25,
        'terreno_vgv_ideal' => 15, 'terreno_vgv_limite' => 20,
        'terreno_custo_obra_ideal' => 25, 'terreno_custo_obra_limite' => 35,
        'ltv_ideal' => 55, 'ltv_limite' => 50,
    ]));

    $result = (new CalculateProjectIndicators)->handle($project);

    expect($result['indicators'])->toHaveCount(9)
        ->and(collect($result['indicators'])->pluck('value')->all())->toBe([
            70.0, 35.0, 50.0, 114.29, 142.86, 33.33, 10.0, 20.0, 66.67,
        ])
        ->and(collect($result['indicators'])->pluck('classification')->unique()->all())->toBe(['Enquadrado'])
        ->and($result['units']['paid_percentage'])->toBe(20.0)
        ->and($result['units']['paid_average_value'])->toBe(1.0)
        ->and($result['units']['unpaid_average_value'])->toBe(8.0)
        ->and($result['units']['stock_average_value'])->toEqualWithDelta(1.6667, 0.0001)
        ->and($result['units']['total_average_value'])->toBe(2.0)
        ->and($result['receivables']['total'])->toBe(100.0)
        ->and($result['land']['value_per_square_meter'])->toBe(2.0);
});

it('classifies both lower-is-better and higher-is-better boundaries', function () {
    $calculator = new CalculateProjectIndicators;

    expect($calculator->classify(70, 70, 90, false))->toBe(ProjectIndicatorClassification::Enquadrado)
        ->and($calculator->classify(80, 70, 90, false))->toBe(ProjectIndicatorClassification::Analisar)
        ->and($calculator->classify(91, 70, 90, false))->toBe(ProjectIndicatorClassification::Desenquadrado)
        ->and($calculator->classify(100, 100, 95, true))->toBe(ProjectIndicatorClassification::Enquadrado)
        ->and($calculator->classify(97, 100, 95, true))->toBe(ProjectIndicatorClassification::Analisar)
        ->and($calculator->classify(94, 100, 95, true))->toBe(ProjectIndicatorClassification::Desenquadrado)
        ->and($calculator->classify(null, 100, 95, true))->toBe(ProjectIndicatorClassification::NaoInformado);
});

it('matches the anonymized numeric baseline from the NimbusForms production-copy dump', function () {
    $project = new ProposalProject([
        'requested_amount' => 17000000,
        'land_market_value' => 3300000,
        'land_area' => 243696,
        'exchanged_units' => 146,
        'paid_units' => 0,
        'unpaid_units' => 74,
        'stock_units' => 309,
        'units_total' => 529,
        'sales_percentage' => 19.32,
        'incurred_cost' => 720000,
        'cost_to_incur' => 15278282,
        'total_cost' => 15998282,
        'work_stage_percentage' => 4.50,
        'paid_sales_value' => 1792102,
        'unpaid_sales_value' => 16973792.81,
        'stock_sales_value' => 82126105.19,
        'gross_sales_value' => 100892000,
        'received_value' => 1792102,
        'value_until_keys' => 3874758,
        'value_after_keys' => 13099034.81,
    ]);
    $project->setRelation('indicators', new ProjectIndicator([
        'financiamento_custo_obra_ideal' => 70, 'financiamento_custo_obra_limite' => 90,
        'financiamento_vgv_ideal' => 65, 'financiamento_vgv_limite' => 75,
        'custo_obra_vgv_ideal' => 65, 'custo_obra_vgv_limite' => 75,
        'recebiveis_vfcto_ideal' => 100, 'recebiveis_vfcto_limite' => 95,
        'recebiveis_terreno_vfcto_ideal' => 100, 'recebiveis_terreno_vfcto_limite' => 95,
        'vendas_liquido_permutas_ideal' => 30, 'vendas_liquido_permutas_limite' => 25,
        'terreno_vgv_ideal' => 15, 'terreno_vgv_limite' => 20,
        'terreno_custo_obra_ideal' => 25, 'terreno_custo_obra_limite' => 35,
        'ltv_ideal' => 55, 'ltv_limite' => 50,
    ]));

    $indicators = collect((new CalculateProjectIndicators)->handle($project)['indicators']);

    expect($indicators->pluck('value')->all())->toBe([
        106.26, 16.85, 15.86, 99.85, 119.26, 19.32, 3.27, 20.63, 86.24,
    ])->and($indicators->pluck('classification')->all())->toBe([
        'Desenquadrado', 'Enquadrado', 'Enquadrado', 'Analisar', 'Enquadrado',
        'Desenquadrado', 'Enquadrado', 'Enquadrado', 'Enquadrado',
    ]);
});
