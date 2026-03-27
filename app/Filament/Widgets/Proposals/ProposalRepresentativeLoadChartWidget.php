<?php

namespace App\Filament\Widgets\Proposals;

use App\Support\Proposals\ProposalDashboardData;
use Filament\Widgets\ChartWidget;

class ProposalRepresentativeLoadChartWidget extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Propostas por representante';

    protected ?string $description = 'Carteira ativa por representante comercial, excluindo propostas rejeitadas ou concluídas.';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasAnyRole(['super-admin', 'admin']);
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $representatives = app(ProposalDashboardData::class)->representativeLoad();

        return [
            'labels' => $representatives->pluck('name')->all(),
            'datasets' => [[
                'label' => 'Carteira ativa',
                'data' => $representatives->pluck('active_proposals_count')->all(),
                'backgroundColor' => '#1d4ed8',
                'borderRadius' => 10,
            ]],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => false,
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
