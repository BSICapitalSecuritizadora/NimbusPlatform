<?php

namespace App\Filament\Resources\ProposalRepresentatives\Pages;

use App\Filament\Resources\ProposalRepresentatives\ProposalRepresentativeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProposalRepresentatives extends ListRecords
{
    protected static string $resource = ProposalRepresentativeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
