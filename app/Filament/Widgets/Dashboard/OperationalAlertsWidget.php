<?php

namespace App\Filament\Widgets\Dashboard;

use App\Models\Obligation;
use App\Models\ObligationEvidence;
use App\Models\Proposal;
use Filament\Widgets\Widget;

class OperationalAlertsWidget extends Widget
{
    protected string $view = 'filament.widgets.dashboard.operational-alerts-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 3;

    protected function getViewData(): array
    {
        $alerts = collect();

        // Obrigações vencidas
        if (auth()->user()->can('obligations.view')) {
            $overdueCount = Obligation::query()->where('status', 'vencida')->count();
            if ($overdueCount > 0) {
                $alerts->push([
                    'title' => "{$overdueCount} Obrigações Vencidas",
                    'description' => 'Ações pendentes com prazo expirado.',
                    'color' => 'danger',
                    'icon' => 'heroicon-o-exclamation-circle',
                    'url' => \App\Filament\Pages\ObligationDashboard::getUrl() ?? '#',
                ]);
            }
        }

        // Evidências Rejeitadas
        if (auth()->user()->can('obligations.view')) {
            $rejectedEvidences = ObligationEvidence::query()->where('status', 'rejected')->count();
            if ($rejectedEvidences > 0) {
                $alerts->push([
                    'title' => "{$rejectedEvidences} Evidências Rejeitadas",
                    'description' => 'Evidências que foram avaliadas e recusadas.',
                    'color' => 'danger',
                    'icon' => 'heroicon-o-x-circle',
                    'url' => '#',
                ]);
            }
        }

        // Propostas sem Responsável
        if (auth()->user()->can('proposals.view')) {
            $unassignedProposals = Proposal::query()->whereNull('representative_id')->whereNotIn('status', ['rejeitado', 'concluida'])->count();
            if ($unassignedProposals > 0) {
                $alerts->push([
                    'title' => "{$unassignedProposals} Propostas sem Responsável",
                    'description' => 'Propostas em aberto precisando de atribuição.',
                    'color' => 'warning',
                    'icon' => 'heroicon-o-user-minus',
                    'url' => \App\Filament\Resources\Proposals\ProposalResource::getUrl('index'),
                ]);
            }
        }

        // Emissões sem coordenador líder (exemplo de dado crítico)
        if (auth()->user()->can('emissions.view')) {
            $emissionsWithoutCoordinator = \App\Models\Emission::query()->where('status', 'draft')->count();
            if ($emissionsWithoutCoordinator > 0) {
                $alerts->push([
                    'title' => "{$emissionsWithoutCoordinator} Emissões em Rascunho",
                    'description' => 'Emissões não ativadas, aguardando preenchimento.',
                    'color' => 'info',
                    'icon' => 'heroicon-o-document',
                    'url' => \App\Filament\Resources\Emissions\EmissionResource::getUrl('index'),
                ]);
            }
        }

        return [
            'alerts' => $alerts,
        ];
    }
}
