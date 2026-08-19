<?php

namespace App\Filament\Widgets\Dashboard;

use App\Filament\Pages\ObligationDashboard;
use App\Filament\Resources\Proposals\ProposalResource;
use App\Models\Obligation;
use App\Models\Proposal;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class MyPendingsWidget extends Widget
{
    private const PREVIEW_LIMIT = 3;

    protected string $view = 'filament.widgets.dashboard.my-pendings-widget';

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'xl' => 7,
    ];

    protected static ?int $sort = 2;

    protected function getViewData(): array
    {
        $user = Auth::user();

        $proposals = collect();
        $proposalCount = 0;
        if ($user->can('proposals.view')) {
            $proposalQuery = Proposal::query()
                ->with('company:id,name')
                ->where('assigned_representative_id', $user->proposalRepresentative?->id ?? -1)
                ->whereNotIn('status', ['rejeitado', 'concluida']);

            $proposalCount = (clone $proposalQuery)->count();
            $proposals = $proposalQuery
                ->latest()
                ->limit(self::PREVIEW_LIMIT)
                ->get();
        }

        $obligations = collect();
        $obligationCount = 0;
        $overdueObligationCount = 0;
        $dueTodayObligationCount = 0;
        if ($user->can('obligations.view')) {
            $obligationQuery = Obligation::query()
                ->where('responsible_user_id', $user->id)
                ->whereIn('status', ['a_vencer', 'vencida', 'em_analise']);

            $obligationSummary = (clone $obligationQuery)
                ->selectRaw('COUNT(*) as total_count')
                ->selectRaw("COALESCE(SUM(CASE WHEN status = 'vencida' THEN 1 ELSE 0 END), 0) as overdue_count")
                ->first();

            $obligationCount = (int) $obligationSummary?->total_count;
            $overdueObligationCount = (int) $obligationSummary?->overdue_count;
            $dueTodayObligationCount = (clone $obligationQuery)
                ->whereDate('due_date', today())
                ->where('status', '!=', 'vencida')
                ->count();
            $obligations = $obligationQuery
                ->orderByRaw('due_date IS NULL')
                ->orderBy('due_date', 'asc')
                ->limit(self::PREVIEW_LIMIT)
                ->get();
        }

        $totalPendingCount = $obligationCount + $proposalCount;

        return [
            'proposals' => $proposals,
            'proposalCount' => $proposalCount,
            'proposalHiddenCount' => max($proposalCount - $proposals->count(), 0),
            'proposalsUrl' => ProposalResource::canViewAny()
                ? ProposalResource::getUrl('index')
                : null,
            'obligations' => $obligations,
            'obligationCount' => $obligationCount,
            'obligationHiddenCount' => max($obligationCount - $obligations->count(), 0),
            'overdueObligationCount' => $overdueObligationCount,
            'dueTodayObligationCount' => $dueTodayObligationCount,
            'obligationsUrl' => ObligationDashboard::canAccess()
                ? ObligationDashboard::getUrl(['filters' => ['responsible_user_id' => $user->id]])
                : null,
            'totalPendingCount' => $totalPendingCount,
            'sectionDescription' => $totalPendingCount === 0
                ? 'Seu fluxo pessoal está sob controle.'
                : $totalPendingCount.' '.($totalPendingCount === 1 ? 'item exige' : 'itens exigem').' acompanhamento.',
        ];
    }
}
