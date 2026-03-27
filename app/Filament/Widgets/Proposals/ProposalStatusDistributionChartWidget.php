<?php

namespace App\Filament\Widgets\Proposals;

use App\Models\Proposal;
use App\Support\Proposals\ProposalDashboardData;
use Filament\Widgets\ChartWidget;

class ProposalStatusDistributionChartWidget extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Distribuição por status';

    protected ?string $description = 'Mostra como a carteira está distribuída entre os principais status do processo.';

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $distribution = app(ProposalDashboardData::class)->statusDistribution();

        return [
            'labels' => array_map(
                fn (string $status): string => Proposal::statusLabelFor($status),
                array_keys($distribution),
            ),
            'datasets' => [[
                'data' => array_values($distribution),
                'backgroundColor' => [
                    '#f59e0b',
                    '#3b82f6',
                    '#f97316',
                    '#10b981',
                    '#ef4444',
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
