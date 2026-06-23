<?php

namespace App\Filament\Widgets\Obligations;

use App\Models\Obligation;
use App\Services\Obligations\ObligationDashboardData;
use Filament\Widgets\ChartWidget;

class ObligationPriorityDistributionChartWidget extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Pendências por Prioridade';

    protected ?string $description = 'Distribuição das obrigações em aberto por prioridade operacional.';

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $distribution = app(ObligationDashboardData::class)->priorityDistribution();

        return [
            'labels' => array_map(
                fn (string $priority): string => Obligation::PRIORITY_OPTIONS[$priority] ?? $priority,
                array_keys($distribution),
            ),
            'datasets' => [[
                'data' => array_values($distribution),
                'backgroundColor' => [
                    '#94a3b8',
                    '#3b82f6',
                    '#f59e0b',
                    '#ef4444',
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
