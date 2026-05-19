<?php

namespace App\Filament\Widgets\Proposals;

use App\Support\Proposals\ProposalDashboardData;
use Filament\Widgets\ChartWidget;

class ProposalVolumeChartWidget extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Evolução de Envios por Período';

    protected ?string $description = 'Volume de novos envios e formalizações nos últimos 6 meses.';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $series = app(ProposalDashboardData::class)->monthlyVolume();

        return [
            'labels' => $series['labels'],
            'datasets' => [
                [
                    'label' => 'Novas Propostas',
                    'data' => $series['received'],
                    'borderColor' => '#1d4ed8',
                    'backgroundColor' => 'rgba(29, 78, 216, 0.16)',
                    'tension' => 0.35,
                    'fill' => true,
                ],
                [
                    'label' => 'Formalizações',
                    'data' => $series['completed'],
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.12)',
                    'tension' => 0.35,
                    'fill' => true,
                ],
            ],
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
