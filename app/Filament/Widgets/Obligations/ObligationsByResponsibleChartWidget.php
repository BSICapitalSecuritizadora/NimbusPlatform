<?php

namespace App\Filament\Widgets\Obligations;

use App\Services\Obligations\ObligationDashboardData;
use Filament\Widgets\ChartWidget;

class ObligationsByResponsibleChartWidget extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Pendências por Responsável';

    protected ?string $description = 'Responsáveis que concentram mais obrigações em aberto e atrasos.';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $responsibles = app(ObligationDashboardData::class)->topResponsiblesByPending();

        return [
            'labels' => $responsibles->pluck('name')->all(),
            'datasets' => [
                [
                    'label' => 'Em aberto',
                    'data' => $responsibles
                        ->map(fn (object $item): int => (int) $item->pending_obligations_count - (int) $item->overdue_obligations_count)
                        ->all(),
                    'backgroundColor' => '#3b82f6',
                ],
                [
                    'label' => 'Vencidas',
                    'data' => $responsibles
                        ->map(fn (object $item): int => (int) $item->overdue_obligations_count)
                        ->all(),
                    'backgroundColor' => '#ef4444',
                ],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => ['stacked' => true],
                'y' => [
                    'stacked' => true,
                    'beginAtZero' => true,
                    'ticks' => ['precision' => 0],
                ],
            ],
            'plugins' => [
                'legend' => ['position' => 'bottom'],
            ],
        ];
    }
}
