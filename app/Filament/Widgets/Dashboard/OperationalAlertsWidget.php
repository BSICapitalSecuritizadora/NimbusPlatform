<?php

namespace App\Filament\Widgets\Dashboard;

use App\Enums\AccessPermission;
use App\Filament\Pages\ObligationDashboard;
use App\Filament\Resources\Emissions\EmissionResource;
use App\Filament\Resources\Proposals\ProposalResource;
use App\Models\Emission;
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

        if (auth()->user()->can('obligations.view')) {
            $overdueCount = Obligation::query()->where('status', 'vencida')->count();
            if ($overdueCount > 0) {
                $alerts->push([
                    'key' => 'overdue-obligations',
                    'count' => $overdueCount,
                    'title' => $overdueCount === 1 ? 'Obrigação vencida' : 'Obrigações vencidas',
                    'description' => 'Prazo expirado e ação pendente.',
                    'severity' => 'Crítico',
                    'tone' => 'danger',
                    'icon' => 'heroicon-o-exclamation-circle',
                    'action' => 'Revisar obrigações',
                    'priority' => 400,
                    'url' => ObligationDashboard::canAccess()
                        ? ObligationDashboard::getUrl(['filters' => ['due_window' => 'overdue']])
                        : null,
                ]);
            }
        }

        if (auth()->user()->can('obligations.view')) {
            $rejectedEvidences = ObligationEvidence::query()->where('status', 'rejected')->count();
            if ($rejectedEvidences > 0) {
                $alerts->push([
                    'key' => 'rejected-evidences',
                    'count' => $rejectedEvidences,
                    'title' => $rejectedEvidences === 1 ? 'Evidência rejeitada' : 'Evidências rejeitadas',
                    'description' => 'Recusadas na revisão e aguardando correção.',
                    'severity' => 'Importante',
                    'tone' => 'warning',
                    'icon' => 'heroicon-o-x-circle',
                    'action' => 'Revisar evidências',
                    'priority' => 300,
                    'url' => ObligationDashboard::canAccess()
                        && auth()->user()->can(AccessPermission::ObligationsViewEvidence->value)
                            ? ObligationDashboard::getUrl(['filters' => ['evidence_state' => ObligationEvidence::STATUS_REJECTED]])
                            : null,
                ]);
            }
        }

        if (auth()->user()->can('proposals.view')) {
            $unassignedProposals = Proposal::query()->whereNull('assigned_representative_id')->whereNotIn('status', ['rejeitado', 'concluida'])->count();
            if ($unassignedProposals > 0) {
                $alerts->push([
                    'key' => 'unassigned-proposals',
                    'count' => $unassignedProposals,
                    'title' => $unassignedProposals === 1 ? 'Proposta sem responsável' : 'Propostas sem responsável',
                    'description' => 'Aguardando atribuição para continuidade.',
                    'severity' => 'Atenção',
                    'tone' => 'warning',
                    'icon' => 'heroicon-o-user-minus',
                    'action' => 'Atribuir propostas',
                    'priority' => 200,
                    'url' => ProposalResource::getUrl('index'),
                ]);
            }
        }

        if (auth()->user()->can('emissions.view')) {
            $emissionsWithoutCoordinator = Emission::query()->where('status', 'draft')->count();
            if ($emissionsWithoutCoordinator > 0) {
                $alerts->push([
                    'key' => 'draft-emissions',
                    'count' => $emissionsWithoutCoordinator,
                    'title' => $emissionsWithoutCoordinator === 1 ? 'Emissão em rascunho' : 'Emissões em rascunho',
                    'description' => 'Aguardando preenchimento para ativação.',
                    'severity' => 'Informativo',
                    'tone' => 'info',
                    'icon' => 'heroicon-o-document',
                    'action' => 'Ver emissões',
                    'priority' => 100,
                    'url' => EmissionResource::getUrl('index'),
                ]);
            }
        }

        return [
            'alerts' => $alerts->sortByDesc('priority')->values(),
        ];
    }
}
