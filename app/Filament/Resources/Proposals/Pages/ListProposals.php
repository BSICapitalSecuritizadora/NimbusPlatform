<?php

namespace App\Filament\Resources\Proposals\Pages;

use App\Filament\Resources\Proposals\ProposalResource;
use Filament\Resources\Pages\ListRecords;

class ListProposals extends ListRecords
{
    protected static string $resource = ProposalResource::class;

    public function getTitle(): string
    {
        return auth()->user()?->hasRole('commercial-representative')
            ? 'Minhas Propostas'
            : 'Propostas';
    }

    public function getTabs(): array
    {
        return [
            'all' => \Filament\Resources\Components\Tab::make('Todas'),
            'new' => \Filament\Resources\Components\Tab::make('Aguardando')
                ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->whereIn('status', [\App\Enums\ProposalStatus::AwaitingCompletion, \App\Enums\ProposalStatus::AwaitingInformation])),
            'review' => \Filament\Resources\Components\Tab::make('Em Análise')
                ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->where('status', \App\Enums\ProposalStatus::InReview)),
            'approved' => \Filament\Resources\Components\Tab::make('Aprovadas')
                ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->where('status', \App\Enums\ProposalStatus::Approved)),
            'rejected' => \Filament\Resources\Components\Tab::make('Recusadas')
                ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->where('status', \App\Enums\ProposalStatus::Rejected)),
        ];
    }
}
