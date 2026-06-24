<?php

namespace App\Filament\Widgets\Obligations;

use App\Enums\AccessPermission;
use App\Models\Obligation;
use App\Services\Obligations\ObligationDashboardData;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class ObligationPriorityDistributionChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Pendências por Prioridade';

    protected ?string $description = 'Distribuição das obrigações em aberto por prioridade no recorte atual.';

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $canViewEvidence = (bool) auth()->user()?->can(AccessPermission::ObligationsViewEvidence->value);
        $filters = app(ObligationDashboardData::class)->sanitizeFilters($this->pageFilters, $canViewEvidence);
        $distribution = app(ObligationDashboardData::class)->priorityDistribution($filters);

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
