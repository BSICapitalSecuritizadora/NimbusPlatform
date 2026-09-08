<?php

namespace App\Filament\Widgets\Proposals;

use App\Filament\Resources\Proposals\ProposalResource;
use App\Support\Proposals\ProposalDashboardData;
use Filament\Widgets\ChartWidget;

class ProposalStatusDistributionChartWidget extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Composição da Carteira';

    protected ?string $description = 'Distribuição por estágio operacional.';

    protected string $view = 'filament.widgets.proposals.proposal-status-distribution-chart-widget';

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'xl' => 5,
    ];

    protected ?string $maxHeight = '140px';

    /**
     * @return array{
     *     total: int,
     *     items: array<int, array{status: string, label: string, count: int, percentage: float, color_hex: string, color_name: string}>,
     *     active_items: array<int, array{status: string, label: string, count: int, percentage: float, color_hex: string, color_name: string}>,
     *     inactive_items_count: int,
     *     dominant_item: ?array{status: string, label: string, count: int, percentage: float}
     * }
     */
    public function getDetails(): array
    {
        return app(ProposalDashboardData::class)->statusDistributionDetails();
    }

    public function getProposalsUrl(): string
    {
        return ProposalResource::getUrl('index');
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $details = $this->getDetails();

        if ($details['total'] === 0) {
            return [
                'labels' => ['Sem propostas'],
                'datasets' => [[
                    'data' => [1],
                    'backgroundColor' => ['#334155'],
                    'borderWidth' => 0,
                ]],
            ];
        }

        $isSingleItem = count($details['active_items']) <= 1;

        return [
            'labels' => array_column($details['active_items'], 'label'),
            'datasets' => [[
                'data' => array_column($details['active_items'], 'count'),
                'backgroundColor' => array_column($details['active_items'], 'color_hex'),
                'borderWidth' => $isSingleItem ? 0 : 2,
                'borderColor' => '#091b23',
                'hoverOffset' => 3,
            ]],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'cutout' => '70%',
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => [
                    'display' => false,
                ],
                'tooltip' => [
                    'padding' => 8,
                    'boxPadding' => 4,
                    'usePointStyle' => true,
                ],
            ],
        ];
    }
}
