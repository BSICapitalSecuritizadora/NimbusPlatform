<?php

namespace App\Filament\Widgets\Obligations;

use App\Enums\AccessPermission;
use App\Services\Obligations\ObligationDashboardData;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class ObligationsByResponsibleChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Pendências por Responsável';

    protected ?string $description = 'Responsáveis com maior concentração de obrigações em aberto e atrasos.';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $canViewEvidence = (bool) auth()->user()?->can(AccessPermission::ObligationsViewEvidence->value);
        $filters = app(ObligationDashboardData::class)->sanitizeFilters($this->pageFilters, $canViewEvidence);
        $responsibles = app(ObligationDashboardData::class)->topResponsiblesByPending(filters: $filters);

        return [
            'labels' => $responsibles->pluck('name')->all(),
            'datasets' => [
                [
                    'label' => 'Em aberto',
                    'data' => $responsibles
                        ->map(fn (object $item): int => max(
                            0,
                            (int) $item->pending_obligations_count - (int) $item->overdue_obligations_count
                        ))
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
