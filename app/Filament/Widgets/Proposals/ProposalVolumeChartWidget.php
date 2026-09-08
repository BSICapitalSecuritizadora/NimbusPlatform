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
        'xl' => 7,
    ];

    protected ?string $maxHeight = '270px';

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
                    'borderRadius' => 4,
                    'borderSkipped' => false,
                    'barPercentage' => 0.62,
                    'categoryPercentage' => 0.72,
                ],
                [
                    'label' => 'Formalizações Concluídas',
                    'data' => $series['completed'],
                    'backgroundColor' => '#059669',
                    'hoverBackgroundColor' => '#047857',
                    'borderRadius' => 4,
                    'borderSkipped' => false,
                    'barPercentage' => 0.62,
                    'categoryPercentage' => 0.72,
                ],
            ],
        ];
    }

    protected function getOptions(): array
    {
        $months = (int) ($this->filter ?? 6);
        $series = app(ProposalDashboardData::class)->monthlyVolume($months);
        $tickColors = [];
        foreach ($series['labels'] as $idx => $label) {
            $hasActivity = (($series['received'][$idx] ?? 0) > 0) || (($series['completed'][$idx] ?? 0) > 0);
            $tickColors[] = $hasActivity ? '#d4af37' : '#64748b';
        }

        return [
            'layout' => [
                'padding' => [
                    'top' => 4,
                    'bottom' => 0,
                    'left' => 0,
                    'right' => 0,
                ],
            ],
            'plugins' => [
                'legend' => [
                    'position' => 'top',
                    'align' => 'end',
                    'labels' => [
                        'boxWidth' => 8,
                        'boxHeight' => 8,
                        'usePointStyle' => true,
                        'pointStyle' => 'circle',
                        'padding' => 12,
                        'color' => '#9ca3af',
                        'font' => [
                            'size' => 11,
                            'weight' => '500',
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
                    'border' => [
                        'display' => false,
                    ],
                    'grid' => [
                        'color' => 'rgba(255, 255, 255, 0.05)',
                        'drawTicks' => false,
                    ],
                    'ticks' => [
                        'precision' => 0,
                        'stepSize' => 1,
                        'padding' => 8,
                        'color' => '#64748b',
                        'font' => [
                            'size' => 11,
                        ],
                    ],
                ],
                'x' => [
                    'border' => [
                        'color' => 'rgba(255, 255, 255, 0.08)',
                    ],
                    'grid' => [
                        'display' => false,
                    ],
                    'ticks' => [
                        'color' => $tickColors,
                        'padding' => 6,
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
