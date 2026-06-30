<?php

namespace App\Filament\Widgets\Dashboard;

use App\Models\Emission;
use App\Models\Fund;
use App\Models\Obligation;
use App\Models\ObligationEvidence;
use App\Models\Proposal;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ExecutiveIndicatorsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Indicadores Executivos (Visão Geral)';

    protected function getStats(): array
    {
        $stats = [];

        if (auth()->user()->can('obligations.view')) {
            $stats[] = Stat::make('Obrigações Vencidas', Obligation::where('status', 'vencida')->count())
                ->description('Atrasadas e em aberto')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('danger');

            $stats[] = Stat::make('Obrigações no Prazo', Obligation::whereIn('status', ['em_dia', 'concluida'])->count())
                ->description('Cumpridas ou dentro do prazo')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success');

            $stats[] = Stat::make('Evidências Pendentes', ObligationEvidence::where('status', 'pending')->count())
                ->description('Aguardando revisão')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning');
        }

        if (auth()->user()->can('proposals.view')) {
            $stats[] = Stat::make('Propostas em Análise', Proposal::whereIn('status', ['em_analise', 'aguardando_complementacao', 'aguardando_informacoes'])->count())
                ->description('Em fluxo comercial ativo')
                ->descriptionIcon('heroicon-m-document-magnifying-glass')
                ->color('info');
        }

        if (auth()->user()->can('emissions.view')) {
            $stats[] = Stat::make('Emissões Ativas', Emission::where('status', 'active')->count())
                ->description('Operações sendo monitoradas')
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color('primary');
        }

        if (auth()->user()->can('funds.view')) {
            $stats[] = Stat::make('Fundos', Fund::count())
                ->description('Total de fundos cadastrados')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('gray');
        }

        return $stats;
    }
}
