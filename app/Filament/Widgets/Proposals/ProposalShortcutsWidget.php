<?php

namespace App\Filament\Widgets\Proposals;

use App\Filament\Resources\ProposalRepresentatives\ProposalRepresentativeResource;
use App\Filament\Resources\Proposals\ProposalResource;
use App\Support\Proposals\ProposalDashboardData;
use Filament\Widgets\Widget;

class ProposalShortcutsWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected string $view = 'filament.widgets.proposals.proposal-shortcuts-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    protected function getViewData(): array
    {
        $summary = app(ProposalDashboardData::class)->summary();
        $user = auth()->user();
        $canViewProposals = (bool) $user?->hasAnyRole(['super-admin', 'admin', 'commercial-representative'])
            || (bool) $user?->can('proposals.view');

        return [
            'summary' => $summary,
            'canViewProposals' => $canViewProposals,
            'canManageRepresentatives' => (bool) $user?->hasAnyRole(['super-admin', 'admin']),
            'isRepresentative' => (bool) $user?->hasRole('commercial-representative'),
            'proposalsUrl' => ProposalResource::getUrl('index'),
            'representativesUrl' => ProposalRepresentativeResource::getUrl('index'),
        ];
    }
}
