<?php

namespace App\Filament\Widgets\Obligations;

use App\Services\Obligations\ObligationDashboardData;
use Filament\Widgets\ChartWidget;

class ObligationOverdueAgingChartWidget extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Aging de Vencidas';

    protected ?string $description = 'Faixas de atraso das obrigações em aberto com vencimento expirado.';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $aging = app(ObligationDashboardData::class)->overdueAging();

        return [
            'labels' => [
                '1 a 7 dias',
                '8 a 15 dias',
                '16 a 30 dias',
                'Mais de 30 dias',
            ],
            'datasets' => [[
                'label' => 'Obrigações',
                'data' => [
                    $aging['days_1_7'],
                    $aging['days_8_15'],
                    $aging['days_16_30'],
                    $aging['days_31_plus'],
                ],
                'backgroundColor' => [
                    '#f59e0b',
                    '#fb7185',
                    '#f97316',
                    '#dc2626',
                ],
                'borderRadius' => 10,
            ]],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => false,
                ],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => [
                        'precision' => 0,
                    ],
                ],
            ],
        ];
    }
}
