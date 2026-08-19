<?php

namespace App\Filament\Resources\Proposals\Pages;

use App\Enums\ProposalStatus;
use App\Filament\Resources\Proposals\ProposalResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListProposals extends ListRecords
{
    protected static string $resource = ProposalResource::class;

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-proposal-list-page',
    ];

    public function getTitle(): string
    {
        return auth()->user()?->hasRole('commercial-representative')
            ? 'Minhas Propostas'
            : 'Carteira de Propostas';
    }

    public function getSubheading(): ?string
    {
        return auth()->user()?->hasRole('commercial-representative')
            ? 'Acompanhamento detalhado das propostas comerciais atribuídas à sua mesa.'
            : 'Gestão institucional do fluxo de captação, triagem de crédito e formalização de propostas.';
    }

    public function getTabs(): array
    {
        $baseQuery = ProposalResource::getEloquentQuery();

        $allCount = (clone $baseQuery)->count();
        $newCount = (clone $baseQuery)->whereIn('status', [
            ProposalStatus::AwaitingCompletion->value,
            ProposalStatus::AwaitingInformation->value,
        ])->count();
        $reviewCount = (clone $baseQuery)->where('status', ProposalStatus::InReview->value)->count();
        $approvedCount = (clone $baseQuery)->where('status', ProposalStatus::Approved->value)->count();
        $rejectedCount = (clone $baseQuery)->where('status', ProposalStatus::Rejected->value)->count();

        return [
            'all' => Tab::make('Todas')
                ->badge($allCount)
                ->badgeColor('gray'),

            'new' => Tab::make('Aguardando')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [
                    ProposalStatus::AwaitingCompletion->value,
                    ProposalStatus::AwaitingInformation->value,
                ]))
                ->badge($newCount)
                ->badgeColor($newCount > 0 ? 'warning' : 'gray'),

            'review' => Tab::make('Em Análise')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ProposalStatus::InReview->value))
                ->badge($reviewCount)
                ->badgeColor($reviewCount > 0 ? 'info' : 'gray'),

            'approved' => Tab::make('Aprovadas')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ProposalStatus::Approved->value))
                ->badge($approvedCount)
                ->badgeColor($approvedCount > 0 ? 'success' : 'gray'),

            'rejected' => Tab::make('Recusadas')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ProposalStatus::Rejected->value))
                ->badge($rejectedCount)
                ->badgeColor($rejectedCount > 0 ? 'danger' : 'gray'),
        ];
    }
}
