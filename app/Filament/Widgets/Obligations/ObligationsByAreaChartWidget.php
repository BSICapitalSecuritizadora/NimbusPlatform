<?php

namespace App\Filament\Widgets\Obligations;

use App\Enums\AccessPermission;
use App\Services\Obligations\ObligationDashboardData;
use Filament\Support\Icons\Heroicon;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class ObligationsByAreaChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Pendências por Área';

    protected ?string $description = 'Áreas com maior volume de obrigações em aberto e vencidas.';

    protected int|string|array $columnSpan = [
        'default' => 12,
        'xl' => 6,
    ];

    protected ?string $maxHeight = '320px';

    protected ?string $emptyStateHeading = 'Nenhuma área com pendências';

    protected ?string $emptyStateDescription = 'Não foram encontradas áreas com obrigações em aberto no recorte de filtros.';

    protected string|\BackedEnum|null $emptyStateIcon = Heroicon::OutlinedSquares2x2;

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
                    'borderRadius' => 6,
                ],
                [
                    'label' => 'Vencidas',
                    'data' => $areas
                        ->map(fn (object $item): int => (int) $item->overdue_obligations_count)
                        ->all(),
                    'backgroundColor' => '#ef4444',
                    'borderRadius' => 6,
                ],
            ],
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
