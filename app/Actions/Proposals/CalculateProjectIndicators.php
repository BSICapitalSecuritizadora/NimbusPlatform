<?php

namespace App\Actions\Proposals;

use App\Enums\ProjectIndicatorClassification;
use App\Enums\ProjectIndicatorDefinition;
use App\Enums\ProjectIndicatorDirection;
use App\Models\ProjectIndicator;
use App\Models\ProposalProject;

class CalculateProjectIndicators
{
    /**
     * @return array{
     *   indicators: array<int, array{key:string,name:string,description:string,value:?float,ideal:?float,limit:?float,unit:string,formula:string,direction:string,classification:string,classification_class:string,classification_color:string}>,
     *   units: array<string, float|int|null>, sales: array<string, float>, costs: array<string, float>, receivables: array<string, float>, land: array<string, ?float>
     * }
     */
    public function handle(ProposalProject $project): array
    {
        $thresholds = $project->indicators;
        $grossSalesValue = (float) $project->gross_sales_value;
        $totalCost = (float) $project->total_cost;
        $landMarketValue = (float) $project->land_market_value;
        $valueAfterKeys = (float) $project->value_after_keys;
        $stockSalesValue = (float) $project->stock_sales_value;
        $totalReceivables = ProposalProject::calculatePaymentFlowTotal(
            $project->received_value,
            $project->value_until_keys,
            $project->value_after_keys,
        );
        $unitsTotal = (int) $project->units_total;
        $indicators = collect(ProjectIndicatorDefinition::cases())->map(function (ProjectIndicatorDefinition $definition) use ($project, $thresholds): array {
            $value = $definition->calculate($project);
            $ideal = $this->threshold($thresholds, $definition->idealAttribute());
            $limit = $this->threshold($thresholds, $definition->limitAttribute());
            $classification = $this->classify($value, $ideal, $limit, $definition->direction());

            return [
                'key' => $definition->value,
                'name' => $definition->label(),
                'description' => $definition->description(),
                'value' => $value,
                'ideal' => $ideal,
                'limit' => $limit,
                'unit' => $definition->unit(),
                'formula' => $definition->formula(),
                'direction' => $definition->direction()->label(),
                'classification' => $classification->label(),
                'classification_class' => $classification->cssClass(),
                'classification_color' => $classification->color(),
            ];
        })->all();

        return [
            'indicators' => $indicators,
            'units' => [
                'exchanged' => (int) $project->exchanged_units,
                'exchanged_percentage' => $this->percentage((int) $project->exchanged_units, $unitsTotal),
                'exchanged_sales_value' => null,
                'exchanged_average_value' => null,
                'paid' => (int) $project->paid_units,
                'paid_percentage' => $this->percentage((int) $project->paid_units, $unitsTotal),
                'paid_sales_value' => (float) $project->paid_sales_value,
                'paid_average_value' => $this->division((float) $project->paid_sales_value, (int) $project->paid_units),
                'unpaid' => (int) $project->unpaid_units,
                'unpaid_percentage' => $this->percentage((int) $project->unpaid_units, $unitsTotal),
                'unpaid_sales_value' => (float) $project->unpaid_sales_value,
                'unpaid_average_value' => $this->division((float) $project->unpaid_sales_value, (int) $project->unpaid_units),
                'stock' => (int) $project->stock_units,
                'stock_percentage' => $this->percentage((int) $project->stock_units, $unitsTotal),
                'stock_sales_value' => $stockSalesValue,
                'stock_average_value' => $this->division($stockSalesValue, (int) $project->stock_units),
                'total' => $unitsTotal,
                'total_percentage' => $unitsTotal > 0 ? 100.0 : null,
                'total_sales_value' => $grossSalesValue,
                'total_average_value' => $this->division($grossSalesValue, $unitsTotal),
                'sold_percentage' => (float) $project->sales_percentage,
            ],
            'sales' => [
                'paid' => (float) $project->paid_sales_value,
                'unpaid' => (float) $project->unpaid_sales_value,
                'stock' => $stockSalesValue,
                'total' => $grossSalesValue,
            ],
            'costs' => [
                'incurred' => (float) $project->incurred_cost,
                'to_incur' => (float) $project->cost_to_incur,
                'total' => $totalCost,
                'work_stage_percentage' => (float) $project->work_stage_percentage,
            ],
            'receivables' => [
                'received' => (float) $project->received_value,
                'until_keys' => (float) $project->value_until_keys,
                'after_keys' => $valueAfterKeys,
                'total' => $totalReceivables,
                'received_percentage' => $this->percentage((float) $project->received_value, $totalReceivables) ?? 0.0,
                'until_keys_percentage' => $this->percentage((float) $project->value_until_keys, $totalReceivables) ?? 0.0,
                'after_keys_percentage' => $this->percentage($valueAfterKeys, $totalReceivables) ?? 0.0,
            ],
            'land' => [
                'market_value' => $landMarketValue,
                'area' => (float) $project->land_area,
                'value_per_square_meter' => $this->division($landMarketValue, (float) $project->land_area),
                'construction_cost_per_land_square_meter' => $this->division($totalCost, (float) $project->land_area),
            ],
        ];
    }

    public function classify(
        ?float $value,
        ?float $ideal,
        ?float $limit,
        ProjectIndicatorDirection $direction,
    ): ProjectIndicatorClassification {
        return $direction->classify($value, $ideal, $limit);
    }

    private function percentage(float|int $numerator, float|int $denominator): ?float
    {
        $division = $this->division($numerator, $denominator);

        return $division === null ? null : round($division * 100, 2);
    }

    private function division(float|int $numerator, float|int $denominator): ?float
    {
        return $denominator > 0 ? $numerator / $denominator : null;
    }

    private function threshold(?ProjectIndicator $thresholds, string $attribute): ?float
    {
        $value = $thresholds?->getAttribute($attribute);

        return $value === null ? null : (float) $value;
    }
}
