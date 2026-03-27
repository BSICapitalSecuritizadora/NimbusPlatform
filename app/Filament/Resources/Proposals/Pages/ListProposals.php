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
}
