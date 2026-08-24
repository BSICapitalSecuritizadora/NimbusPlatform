<?php

namespace App\Filament\Widgets\Obligations;

use App\Enums\AccessPermission;
use App\Models\Obligation;
use App\Services\Obligations\ObligationDashboardData;
use Filament\Support\Icons\Heroicon;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class ObligationPriorityDistributionChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Pendências por Prioridade';

    protected ?string $description = 'Distribuição das obrigações em aberto por prioridade no recorte atual.';

    protected int|string|array $columnSpan = [
        'default' => 12,
        'xl' => 6,
    ];

    protected ?string $maxHeight = '320px';

    protected ?string $emptyStateHeading = 'Nenhuma pendência por prioridade';

    protected ?string $emptyStateDescription = 'Não há obrigações em aberto com prioridade no recorte atual.';

    protected string|\BackedEnum|null $emptyStateIcon = Heroicon::OutlinedShieldCheck;

    protected function getType(): string
    {
        return 'doughnut';
    }

    public function isEmpty(): bool
    {
        $data = $this->getCachedData();
        $dataset = $data['datasets'][0]['data'] ?? [];

        return array_sum($dataset) === 0;
    }

    protected function getData(): array
    {
        $canViewEvidence = (bool) auth()->user()?->can(AccessPermission::ObligationsViewEvidence->value);
        $filters = app(ObligationDashboardData::class)->sanitizeFilters($this->pageFilters, $canViewEvidence);
        $distribution = app(ObligationDashboardData::class)->priorityDistribution($filters);

        return [
            'labels' => array_map(
                fn (string $priority): string => Obligation::PRIORITY_OPTIONS[$priority] ?? $priority,
                array_keys($distribution),
            ),
            'datasets' => [[
                'data' => array_values($distribution),
                'backgroundColor' => [
                    '#94a3b8', // Baixa
                    '#38bdf8', // Média
                    '#f59e0b', // Alta
                    '#ef4444', // Crítica
                ],
                'borderColor' => '#0d252e',
                'borderWidth' => 2,
                'hoverOffset' => 6,
            ]],
        ];
    }

    protected function getOptions(): RawJs|array
    {
        return RawJs::make(<<<'JS'
        {
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 16,
                        usePointStyle: true,
                        pointStyle: 'circle',
                        boxWidth: 10,
                        font: { size: 12 }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.raw || 0;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = total > 0 ? ((value / total) * 100).toFixed(1).replace('.', ',') + '%' : '0%';
                            return ` ${label}: ${value} (${percentage})`;
                        }
                    }
                }
            },
            cutout: '68%',
            maintainAspectRatio: false
        }
        JS);
    }
}
