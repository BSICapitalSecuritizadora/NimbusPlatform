<?php

namespace App\Filament\Widgets\Obligations;

use App\Enums\AccessPermission;
use App\Models\Obligation;
use App\Services\Obligations\ObligationDashboardData;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class ObligationStatusDistributionChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Obrigações por Status';

    protected ?string $description = 'Distribuição do recorte atual por situação operacional.';

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $canViewEvidence = (bool) auth()->user()?->can(AccessPermission::ObligationsViewEvidence->value);
        $filters = app(ObligationDashboardData::class)->sanitizeFilters($this->pageFilters, $canViewEvidence);
        $distribution = app(ObligationDashboardData::class)->statusDistribution($filters);

        return [
            'labels' => array_map(
                fn (string $status): string => Obligation::STATUS_OPTIONS[$status] ?? $status,
                array_keys($distribution),
            ),
            'datasets' => [[
                'data' => array_values($distribution),
                'backgroundColor' => [
                    '#10b981',
                    '#3b82f6',
                    '#ef4444',
                    '#22c55e',
                    '#f59e0b',
                    '#64748b',
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
