<?php

namespace App\Filament\Resources\Constructions\Pages;

use App\Filament\Resources\Constructions\ConstructionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditConstruction extends EditRecord
{
    protected static string $resource = ConstructionResource::class;

    protected static ?string $title = 'Editar obra';

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Obra atualizada com sucesso.';
    }
}
