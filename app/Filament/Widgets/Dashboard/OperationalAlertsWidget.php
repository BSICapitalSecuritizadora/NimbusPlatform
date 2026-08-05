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
                    'title' => "{$overdueCount} obrigações vencidas",
                    'description' => 'Ações pendentes com prazo expirado.',
                    'color' => 'danger',
                    'icon' => 'heroicon-o-exclamation-circle',
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
                    'title' => "{$rejectedEvidences} evidências rejeitadas",
                    'description' => 'Evidências que foram avaliadas e recusadas.',
                    'color' => 'danger',
                    'icon' => 'heroicon-o-x-circle',
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
                    'title' => "{$unassignedProposals} propostas sem responsável",
                    'description' => 'Propostas em aberto precisando de atribuição.',
                    'color' => 'warning',
                    'icon' => 'heroicon-o-user-minus',
                    'url' => ProposalResource::getUrl('index'),
                ]);
            }
        }

        if (auth()->user()->can('emissions.view')) {
            $emissionsWithoutCoordinator = Emission::query()->where('status', 'draft')->count();
            if ($emissionsWithoutCoordinator > 0) {
                $alerts->push([
                    'title' => "{$emissionsWithoutCoordinator} emissões em rascunho",
                    'description' => 'Emissões não ativadas, aguardando preenchimento.',
                    'color' => 'info',
                    'icon' => 'heroicon-o-document',
                    'url' => EmissionResource::getUrl('index'),
                ]);
            }
        }

        return [
            'alerts' => $alerts,
        ];
    }
}
