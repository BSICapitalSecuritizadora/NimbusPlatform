<?php

namespace App\Filament\Widgets\Obligations;

use App\Enums\AccessPermission;
use App\Services\Obligations\ObligationDashboardData;
use Filament\Support\Icons\Heroicon;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class ObligationOverdueAgingChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Aging de Vencidas';

    protected ?string $description = 'Faixas de atraso das obrigações em aberto com vencimento expirado.';

    protected int|string|array $columnSpan = [
        'default' => 12,
        'xl' => 6,
    ];

    protected ?string $maxHeight = '320px';

    protected ?string $emptyStateHeading = 'Nenhuma obrigação vencida para análise de aging';

    protected ?string $emptyStateDescription = 'Não há atrasos registrados no recorte de filtros selecionado.';

    protected string|\BackedEnum|null $emptyStateIcon = Heroicon::OutlinedCheckCircle;

    protected function getType(): string
    {
        return 'bar';
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
        $aging = app(ObligationDashboardData::class)->overdueAging($filters);

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
                'borderRadius' => 8,
            ]],
        ];
    }

    protected function getOptions(): RawJs|array
    {
        return RawJs::make(<<<'JS'
        {
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { font: { size: 11 } }
                },
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0, font: { size: 11 } },
                    grid: { color: 'rgba(255, 255, 255, 0.05)' }
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const value = context.raw || 0;
                            return ` ${value} ${value === 1 ? 'obrigação vencida' : 'obrigações vencidas'}`;
                        }
                    }
                }
            },
            responsive: true,
            maintainAspectRatio: false
        }
        JS);
    }
}
