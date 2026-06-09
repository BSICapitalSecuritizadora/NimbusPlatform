<?php

namespace App\Filament\Resources\Negotiations\Pages;

use App\Filament\Resources\Negotiations\NegotiationResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditNegotiation extends EditRecord
{
    protected static string $resource = NegotiationResource::class;

    protected static ?string $title = 'Editar Negociação';

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->label('Visualizar'),
            DeleteAction::make(),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Negociação atualizada com sucesso.';
    }
}
