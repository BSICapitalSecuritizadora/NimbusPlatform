<?php

namespace App\Filament\Widgets\Obligations;

use App\Enums\AccessPermission;
use App\Models\Emission;
use App\Services\Obligations\ObligationDashboardData;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class ObligationsByEmissionChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Emissões com Pendências';

    protected ?string $description = 'Volume operacional por emissão, com atrasos e fila documental quando permitido.';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $canViewEvidence = (bool) auth()->user()?->can(AccessPermission::ObligationsViewEvidence->value);
        $filters = app(ObligationDashboardData::class)->sanitizeFilters($this->pageFilters, $canViewEvidence);
        $emissions = app(ObligationDashboardData::class)->topEmissionsByPending(filters: $filters);

        $datasets = [
            [
                'label' => 'Em aberto',
                'data' => $emissions
                    ->map(fn (Emission $emission): int => max(
                        0,
                        (int) $emission->pending_obligations_count - (int) $emission->overdue_obligations_count
                    ))
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
        ];

        if ($canViewEvidence) {
            $datasets[] = [
                'label' => 'Evidência Pendente',
                'data' => $emissions
                    ->map(fn (Emission $emission): int => (int) $emission->pending_evidence_obligations_count)
                    ->all(),
                'backgroundColor' => '#f59e0b',
            ];
        }

        return [
            'labels' => $emissions
                ->map(fn (Emission $emission): string => $emission->name)
                ->all(),
            'datasets' => $datasets,
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
