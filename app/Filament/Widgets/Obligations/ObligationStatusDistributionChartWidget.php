<?php

namespace App\Filament\Widgets\Obligations;

use App\Models\Obligation;
use App\Services\Obligations\ObligationDashboardData;
use Filament\Widgets\ChartWidget;

class ObligationStatusDistributionChartWidget extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Obrigações por Status';

    protected ?string $description = 'Distribuição das obrigações por situação em todas as emissões.';

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $distribution = app(ObligationDashboardData::class)->statusDistribution();

        return [
            'labels' => array_map(
                fn (string $status): string => Obligation::STATUS_OPTIONS[$status] ?? $status,
                array_keys($distribution),
            ),
            'datasets' => [[
                'data' => array_values($distribution),
                'backgroundColor' => [
                    '#10b981', // em_dia
                    '#3b82f6', // a_vencer
                    '#ef4444', // vencida
                    '#22c55e', // concluida
                    '#f59e0b', // em_analise
                    '#64748b', // nao_aplicavel
                ],
            ]],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                ],
            ],
        ];
    }
}
