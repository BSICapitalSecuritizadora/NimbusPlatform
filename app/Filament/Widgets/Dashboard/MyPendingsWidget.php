<?php

namespace App\Filament\Widgets\Dashboard;

use App\Models\Obligation;
use App\Models\Proposal;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class MyPendingsWidget extends Widget
{
    protected string $view = 'filament.widgets.dashboard.my-pendings-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    protected function getViewData(): array
    {
        $user = Auth::user();

        $proposals = collect();
        if ($user->can('proposals.view')) {
            $proposals = Proposal::query()
                ->where('assigned_representative_id', $user->proposalRepresentative?->id ?? -1)
                ->whereNotIn('status', ['rejeitado', 'concluida'])
                ->latest()
                ->limit(5)
                ->get();
        }

        $obligations = collect();
        if ($user->can('obligations.view')) {
            $obligations = Obligation::query()
                ->where('responsible_user_id', $user->id)
                ->whereNotIn('status', ['em_dia', 'concluida'])
                ->orderBy('due_date', 'asc')
                ->limit(5)
                ->get();
        }

        return [
            'proposals' => $proposals,
            'obligations' => $obligations,
        ];
    }
}
