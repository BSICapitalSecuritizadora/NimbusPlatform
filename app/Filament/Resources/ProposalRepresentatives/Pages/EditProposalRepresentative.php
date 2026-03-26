<?php

namespace App\Filament\Resources\ProposalRepresentatives\Pages;

use App\Filament\Resources\ProposalRepresentatives\ProposalRepresentativeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProposalRepresentative extends EditRecord
{
    protected static string $resource = ProposalRepresentativeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
