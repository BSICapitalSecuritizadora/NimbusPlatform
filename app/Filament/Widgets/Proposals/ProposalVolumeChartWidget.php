<?php

namespace App\Filament\Widgets\Proposals;

use App\Support\Proposals\ProposalDashboardData;
use Filament\Widgets\ChartWidget;

class ProposalVolumeChartWidget extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Evolução e Formalização de Propostas';

    protected ?string $description = 'Volume de novos envios e formalizações concluídas no período.';

    public ?string $filter = '6';

    protected string $view = 'filament.widgets.proposals.proposal-volume-chart-widget';

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'xl' => 8,
    ];

    protected ?string $maxHeight = '300px';

    protected function getFilters(): ?array
    {
        return [
            '3' => 'Últimos 3 meses',
            '6' => 'Últimos 6 meses',
            '12' => 'Últimos 12 meses',
        ];
    }

    /**
     * @return array{
     *     total_received: int,
     *     total_completed: int,
     *     conversion_rate: float,
     *     peak_month: string,
     *     peak_count: int,
     *     latest_active_month: ?string,
     *     current_month_label: string,
     *     current_month_received: int,
     *     current_month_completed: int,
     *     has_activity: bool
     * }
     */
    public function getMetrics(): array
    {
        $months = (int) ($this->filter ?? 6);

        return app(ProposalDashboardData::class)->monthlyVolumeMetrics($months);
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $months = (int) ($this->filter ?? 6);
        $series = app(ProposalDashboardData::class)->monthlyVolume($months);

        return [
            'labels' => $series['labels'],
            'datasets' => [
                [
                    'label' => 'Novos Envios',
                    'data' => $series['received'],
                    'backgroundColor' => '#b7832f',
                    'hoverBackgroundColor' => '#96651f',
                    'borderRadius' => 6,
                    'borderSkipped' => false,
                    'barPercentage' => 0.65,
                    'categoryPercentage' => 0.7,
                ],
                [
                    'label' => 'Formalizações Concluídas',
                    'data' => $series['completed'],
                    'backgroundColor' => '#059669',
                    'hoverBackgroundColor' => '#047857',
                    'borderRadius' => 6,
                    'borderSkipped' => false,
                    'barPercentage' => 0.65,
                    'categoryPercentage' => 0.7,
                ],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'position' => 'top',
                    'align' => 'end',
                    'labels' => [
                        'boxWidth' => 10,
                        'boxHeight' => 10,
                        'usePointStyle' => true,
                        'pointStyle' => 'circle',
                        'padding' => 14,
                        'font' => [
                            'size' => 12,
                            'weight' => '600',
                        ],
                    ],
                ],
                'tooltip' => [
                    'mode' => 'index',
                    'intersect' => false,
                    'padding' => 10,
                    'boxPadding' => 4,
                    'usePointStyle' => true,
                ],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'grid' => [
                        'color' => 'rgba(219, 213, 211, 0.35)',
                    ],
                    'ticks' => [
                        'precision' => 0,
                        'stepSize' => 1,
                        'font' => [
                            'size' => 11,
                        ],
                    ],
                ],
                'x' => [
                    'grid' => [
                        'display' => false,
                    ],
                    'ticks' => [
                        'font' => [
                            'size' => 11,
                            'weight' => '500',
                        ],
                    ],
                ],
            ],
            'interaction' => [
                'mode' => 'index',
                'intersect' => false,
            ],
        ];
    }
}
