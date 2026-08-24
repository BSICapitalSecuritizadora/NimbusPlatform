<?php

namespace App\Filament\Widgets\Obligations;

use App\Enums\AccessPermission;
use App\Models\Emission;
use App\Services\Obligations\ObligationDashboardData;
use Filament\Support\Icons\Heroicon;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class ObligationsByEmissionChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Emissões com Pendências';

    protected ?string $description = 'Volume operacional por emissão, com atrasos e fila documental quando permitido.';

    protected int|string|array $columnSpan = [
        'default' => 12,
        'xl' => 6,
    ];

    protected ?string $maxHeight = '320px';

    protected ?string $emptyStateHeading = 'Nenhuma emissão com pendências';

    protected ?string $emptyStateDescription = 'Não foram encontradas emissões com obrigações em aberto no recorte de filtros.';

    protected string|\BackedEnum|null $emptyStateIcon = Heroicon::OutlinedBuildingOffice2;

    protected function getType(): string
    {
        return 'bar';
    }

    public function isEmpty(): bool
    {
        $data = $this->getCachedData();
        $labels = $data['labels'] ?? [];

        return empty($labels);
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
                'backgroundColor' => '#38bdf8',
                'borderRadius' => 6,
            ],
            [
                'label' => 'Vencidas',
                'data' => $emissions
                    ->map(fn (Emission $emission): int => (int) $emission->overdue_obligations_count)
                    ->all(),
                'backgroundColor' => '#ef4444',
                'borderRadius' => 6,
            ],
        ];

        if ($canViewEvidence) {
            $datasets[] = [
                'label' => 'Evidência Pendente',
                'data' => $emissions
                    ->map(fn (Emission $emission): int => (int) $emission->pending_evidence_obligations_count)
                    ->all(),
                'backgroundColor' => '#f59e0b',
                'borderRadius' => 6,
            ];
        }

        return [
            'labels' => $emissions
                ->map(fn (Emission $emission): string => $emission->name)
                ->all(),
            'datasets' => $datasets,
        ];
    }

    protected function getOptions(): RawJs|array
    {
        return RawJs::make(<<<'JS'
        {
            scales: {
                x: {
                    stacked: true,
                    grid: { display: false },
                    ticks: {
                        maxRotation: 35,
                        minRotation: 0,
                        font: { size: 11 }
                    }
                },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    ticks: { precision: 0, font: { size: 11 } },
                    grid: { color: 'rgba(255, 255, 255, 0.05)' }
                }
            },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 14,
                        usePointStyle: true,
                        pointStyle: 'circle',
                        boxWidth: 10,
                        font: { size: 12 }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.dataset.label || '';
                            const value = context.raw || 0;
                            return ` ${label}: ${value}`;
                        }
                    }
                }
            },
            maintainAspectRatio: false
        }
        JS);
    }
}
