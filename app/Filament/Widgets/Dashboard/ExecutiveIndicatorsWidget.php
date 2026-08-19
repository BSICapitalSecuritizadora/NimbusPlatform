<?php

namespace App\Filament\Widgets\Dashboard;

use App\Filament\Pages\ObligationDashboard;
use App\Filament\Resources\Emissions\EmissionResource;
use App\Filament\Resources\Funds\FundResource;
use App\Filament\Resources\Proposals\ProposalResource;
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

    protected ?string $pollingInterval = '60s';

    protected ?string $heading = 'Indicadores executivos';

    protected ?string $description = 'Leitura consolidada do ambiente administrativo.';

    protected int|array|null $columns = [
        'default' => 2,
        'md' => 3,
    ];

    protected function getStats(): array
    {
        $stats = [];

        if (auth()->user()->can('obligations.view')) {
            $stats[] = Stat::make('Obrigações vencidas', Obligation::where('status', 'vencida')->count())
                ->description('Atrasadas e em aberto')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger')
                ->url(ObligationDashboard::canAccess()
                    ? ObligationDashboard::getUrl(['filters' => ['due_window' => 'overdue']])
                    : null)
                ->extraAttributes(['class' => 'bsi-cockpit-stat']);

            $stats[] = Stat::make('Obrigações no prazo', Obligation::whereIn('status', ['em_dia', 'concluida'])->count())
                ->description('Cumpridas ou dentro do prazo')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->url(ObligationDashboard::canAccess() ? ObligationDashboard::getUrl() : null)
                ->extraAttributes(['class' => 'bsi-cockpit-stat']);

            $stats[] = Stat::make('Evidências pendentes', ObligationEvidence::where('status', 'pending')->count())
                ->description('Aguardando revisão')
                ->icon('heroicon-o-clock')
                ->color('warning')
                ->url(ObligationDashboard::canAccess()
                    ? ObligationDashboard::getUrl(['filters' => ['evidence_state' => ObligationEvidence::STATUS_PENDING]])
                    : null)
                ->extraAttributes(['class' => 'bsi-cockpit-stat']);
        }

        if (auth()->user()->can('proposals.view')) {
            $stats[] = Stat::make('Propostas em análise', Proposal::whereIn('status', ['em_analise', 'aguardando_complementacao', 'aguardando_informacoes'])->count())
                ->description('Em fluxo comercial ativo')
                ->icon('heroicon-o-document-magnifying-glass')
                ->color('info')
                ->url(ProposalResource::getUrl('index'))
                ->extraAttributes(['class' => 'bsi-cockpit-stat']);
        }

        if (auth()->user()->can('emissions.view')) {
            $stats[] = Stat::make('Emissões ativas', Emission::where('status', 'active')->count())
                ->description('Operações sendo monitoradas')
                ->icon('heroicon-o-chart-bar')
                ->color('primary')
                ->url(EmissionResource::getUrl('index'))
                ->extraAttributes(['class' => 'bsi-cockpit-stat']);
        }

        if (auth()->user()->can('funds.view')) {
            $stats[] = Stat::make('Fundos', Fund::count())
                ->description('Total de fundos cadastrados')
                ->icon('heroicon-o-banknotes')
                ->color('gray')
                ->url(FundResource::getUrl('index'))
                ->extraAttributes(['class' => 'bsi-cockpit-stat']);
        }

        return $stats;
    }
}
