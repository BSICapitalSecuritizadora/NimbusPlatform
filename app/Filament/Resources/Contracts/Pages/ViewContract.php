<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Filament\Resources\Contracts\ContractResource;
use App\Models\Contract;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\ViewRecord;

class ViewContract extends ViewRecord
{
    protected static string $resource = ContractResource::class;

    protected static ?string $breadcrumb = 'Visualizar';

    public function getTitle(): string
    {
        return 'Contrato '.$this->getRecord()->code;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label('Editar')
                ->visible(fn (Contract $record): bool => ContractResource::canEdit($record)),
            RestoreAction::make()
                ->label('Restaurar Contrato')
                ->visible(fn (Contract $record): bool => ContractResource::canRestore($record)),
        ];
    }
}
