<?php

namespace App\Filament\Widgets\Obligations;

use App\Enums\AccessPermission;
use App\Services\Obligations\ObligationDashboardData;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class ObligationsByAreaChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Pendências por Área';

    protected ?string $description = 'Áreas com maior volume de obrigações em aberto e vencidas.';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $canViewEvidence = (bool) auth()->user()?->can(AccessPermission::ObligationsViewEvidence->value);
        $filters = app(ObligationDashboardData::class)->sanitizeFilters($this->pageFilters, $canViewEvidence);
        $areas = app(ObligationDashboardData::class)->topAreasByPending(filters: $filters);

        return [
            'labels' => $areas->pluck('label')->all(),
            'datasets' => [
                [
                    'label' => 'Em aberto',
                    'data' => $areas
                        ->map(fn (object $item): int => max(
                            0,
                            (int) $item->pending_obligations_count - (int) $item->overdue_obligations_count
                        ))
                        ->all(),
                    'backgroundColor' => '#0f766e',
                ],
                [
                    'label' => 'Vencidas',
                    'data' => $areas
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
