<?php

namespace App\Filament\Widgets\Obligations;

use App\Models\Emission;
use App\Services\Obligations\ObligationDashboardData;
use Filament\Widgets\ChartWidget;

class ObligationsByEmissionChartWidget extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Emissões com Pendências';

    protected ?string $description = 'Emissões que concentram mais obrigações a vencer ou vencidas.';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $emissions = app(ObligationDashboardData::class)->topEmissionsByPending();

        return [
            'labels' => $emissions
                ->map(fn (Emission $emission): string => $emission->name)
                ->all(),
            'datasets' => [
                [
                    'label' => 'A vencer',
                    'data' => $emissions
                        ->map(fn (Emission $emission): int => (int) $emission->pending_obligations_count - (int) $emission->overdue_obligations_count)
                        ->all(),
                    'backgroundColor' => '#3b82f6',
                ],
                [
                    'label' => 'Vencidas',
                    'data' => $emissions
                        ->map(fn (Emission $emission): int => (int) $emission->overdue_obligations_count)
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
