<?php

namespace App\Filament\NimbusWidgets;

use App\Models\Nimbus\Submission;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class NimbusVolumeChart extends ChartWidget
{
    protected ?string $heading = 'Volume de envios (30 dias)';

    protected ?string $description = 'Evolução diária de novas solicitações';

    protected string $view = 'filament.widgets.nimbus.nimbus-volume-chart-widget';

    // Span 8 of 12 columns (~65%)
    protected int|string|array $columnSpan = [
        'default' => 'full',
        'lg' => 8,
        'xl' => 8,
    ];

    protected ?string $maxHeight = '240px';

    /**
     * @return array{
     *     total: int,
     *     daily_average: float,
     *     peak_count: int,
     *     peak_date: string,
     *     has_activity: bool
     * }
     */
    public function getMetrics(): array
    {
        $startDate = Carbon::now()->subDays(29)->startOfDay();
        $endDate = Carbon::now()->endOfDay();

        $submissions = Submission::whereBetween('submitted_at', [$startDate, $endDate])
            ->get()
            ->groupBy(function ($date) {
                return Carbon::parse($date->submitted_at)->format('d/m');
            });

        $total = 0;
        $peakCount = 0;
        $peakDate = '-';

        for ($i = 29; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->format('d/m');
            $count = isset($submissions[$date]) ? count($submissions[$date]) : 0;
            $total += $count;
            if ($count > $peakCount) {
                $peakCount = $count;
                $peakDate = $date;
            }
        }

        return [
            'total' => $total,
            'daily_average' => round($total / 30, 1),
            'peak_count' => $peakCount,
            'peak_date' => $peakDate,
            'has_activity' => $total > 0,
        ];
    }

    protected function getData(): array
    {
        $startDate = Carbon::now()->subDays(29)->startOfDay();
        $endDate = Carbon::now()->endOfDay();

        $submissions = Submission::whereBetween('submitted_at', [$startDate, $endDate])
            ->get()
            ->groupBy(function ($date) {
                return Carbon::parse($date->submitted_at)->format('d/m');
            });

        $labels = [];
        $data = [];

        for ($i = 29; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->format('d/m');
            $labels[] = $date;
            $data[] = isset($submissions[$date]) ? count($submissions[$date]) : 0;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Envios diários',
                    'data' => $data,
                    'fill' => true,
                    'backgroundColor' => 'rgba(56, 189, 248, 0.08)',
                    'borderColor' => '#38bdf8',
                    'borderWidth' => 2,
                    'pointBackgroundColor' => '#38bdf8',
                    'pointBorderColor' => '#0d252e',
                    'pointRadius' => 2.5,
                    'pointHoverRadius' => 5,
                    'tension' => 0.35,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => [
                    'display' => false,
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
                        'color' => 'rgba(148, 163, 184, 0.08)',
                    ],
                    'ticks' => [
                        'precision' => 0,
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
                        'maxTicksLimit' => 8,
                        'maxRotation' => 0,
                        'autoSkip' => true,
                        'font' => [
                            'size' => 11,
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

    protected function getType(): string
    {
        return 'line';
    }
}
