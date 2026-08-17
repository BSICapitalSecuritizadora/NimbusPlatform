<?php

namespace App\Enums;

use App\Models\ProposalProject;

enum ProjectIndicatorDefinition: string
{
    case FinancingToTotalCost = 'financiamento_custo_obra';
    case FinancingToGrossSalesValue = 'financiamento_vgv';
    case TotalCostToGrossSalesValue = 'custo_obra_vgv';
    case ReceivablesToFinancing = 'recebiveis_vfcto';
    case ReceivablesAndLandToFinancing = 'recebiveis_terreno_vfcto';
    case NetSalesExcludingExchanges = 'vendas_liquido_permutas';
    case LandToGrossSalesValue = 'terreno_vgv';
    case LandToTotalCost = 'terreno_custo_obra';
    case StockCoverageLtv = 'ltv';

    public function label(): string
    {
        return match ($this) {
            self::FinancingToTotalCost => 'Financiamento / Custo de obra',
            self::FinancingToGrossSalesValue => 'Financiamento / VGV',
            self::TotalCostToGrossSalesValue => 'Custo da obra / VGV',
            self::ReceivablesToFinancing => 'Recebíveis / Valor do financiamento',
            self::ReceivablesAndLandToFinancing => 'Recebíveis + Terreno / Valor do financiamento',
            self::NetSalesExcludingExchanges => 'Vendas líquidas de permutas',
            self::LandToGrossSalesValue => 'Terreno / VGV',
            self::LandToTotalCost => 'Terreno / Custo de obra',
            self::StockCoverageLtv => 'LTV — cobertura de estoque',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::FinancingToTotalCost => 'Participação do financiamento solicitado no custo total da obra.',
            self::FinancingToGrossSalesValue => 'Participação do financiamento solicitado no VGV do empreendimento.',
            self::TotalCostToGrossSalesValue => 'Participação do custo total da obra no VGV do empreendimento.',
            self::ReceivablesToFinancing => 'Cobertura do financiamento solicitado pelos recebíveis ainda não pagos.',
            self::ReceivablesAndLandToFinancing => 'Cobertura do financiamento pelos recebíveis ainda não pagos somados ao terreno.',
            self::NetSalesExcludingExchanges => 'Participação das unidades vendidas e quitadas nas unidades não permutadas.',
            self::LandToGrossSalesValue => 'Participação do valor de mercado do terreno no VGV.',
            self::LandToTotalCost => 'Participação do valor de mercado do terreno no custo total da obra.',
            self::StockCoverageLtv => 'Participação do estoque no valor pós-chaves somado ao estoque.',
        };
    }

    public function formula(): string
    {
        return match ($this) {
            self::FinancingToTotalCost => 'requested_amount / total_cost',
            self::FinancingToGrossSalesValue => 'requested_amount / gross_sales_value',
            self::TotalCostToGrossSalesValue => 'total_cost / gross_sales_value',
            self::ReceivablesToFinancing => 'unpaid_sales_value / requested_amount',
            self::ReceivablesAndLandToFinancing => '(unpaid_sales_value + land_market_value) / requested_amount',
            self::NetSalesExcludingExchanges => '(paid_units + unpaid_units) / (units_total - exchanged_units)',
            self::LandToGrossSalesValue => 'land_market_value / gross_sales_value',
            self::LandToTotalCost => 'land_market_value / total_cost',
            self::StockCoverageLtv => 'stock_sales_value / (value_after_keys + stock_sales_value)',
        };
    }

    public function direction(): ProjectIndicatorDirection
    {
        return match ($this) {
            self::FinancingToTotalCost,
            self::FinancingToGrossSalesValue,
            self::TotalCostToGrossSalesValue,
            self::LandToGrossSalesValue,
            self::LandToTotalCost => ProjectIndicatorDirection::LessThanOrEqual,
            self::ReceivablesToFinancing,
            self::ReceivablesAndLandToFinancing,
            self::NetSalesExcludingExchanges,
            self::StockCoverageLtv => ProjectIndicatorDirection::GreaterThanOrEqual,
        };
    }

    public function unit(): string
    {
        return '%';
    }

    public function idealAttribute(): string
    {
        return "{$this->value}_ideal";
    }

    public function limitAttribute(): string
    {
        return "{$this->value}_limite";
    }

    public function calculate(ProposalProject $project): ?float
    {
        [$numerator, $denominator] = match ($this) {
            self::FinancingToTotalCost => [$project->requested_amount, $project->total_cost],
            self::FinancingToGrossSalesValue => [$project->requested_amount, $project->gross_sales_value],
            self::TotalCostToGrossSalesValue => [$project->total_cost, $project->gross_sales_value],
            self::ReceivablesToFinancing => [$project->unpaid_sales_value, $project->requested_amount],
            self::ReceivablesAndLandToFinancing => [
                self::sum($project->unpaid_sales_value, $project->land_market_value),
                $project->requested_amount,
            ],
            self::NetSalesExcludingExchanges => [
                self::sum($project->paid_units, $project->unpaid_units),
                self::subtract($project->units_total, $project->exchanged_units),
            ],
            self::LandToGrossSalesValue => [$project->land_market_value, $project->gross_sales_value],
            self::LandToTotalCost => [$project->land_market_value, $project->total_cost],
            self::StockCoverageLtv => [
                $project->stock_sales_value,
                self::sum($project->value_after_keys, $project->stock_sales_value),
            ],
        };

        return self::percentage($numerator, $denominator);
    }

    private static function percentage(float|int|string|null $numerator, float|int|string|null $denominator): ?float
    {
        if ($numerator === null || $denominator === null || ! is_numeric($numerator) || ! is_numeric($denominator)) {
            return null;
        }

        $denominator = (float) $denominator;

        if ($denominator <= 0) {
            return null;
        }

        return round(((float) $numerator / $denominator) * 100, 2);
    }

    private static function sum(float|int|string|null $first, float|int|string|null $second): ?float
    {
        if ($first === null || $second === null || ! is_numeric($first) || ! is_numeric($second)) {
            return null;
        }

        return (float) $first + (float) $second;
    }

    private static function subtract(float|int|string|null $minuend, float|int|string|null $subtrahend): ?float
    {
        if ($minuend === null || $subtrahend === null || ! is_numeric($minuend) || ! is_numeric($subtrahend)) {
            return null;
        }

        return (float) $minuend - (float) $subtrahend;
    }
}
