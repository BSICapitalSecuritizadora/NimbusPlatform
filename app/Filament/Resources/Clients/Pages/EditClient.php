<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditClient extends EditRecord
{
    protected static string $resource = ClientResource::class;

    protected static ?string $title = 'Editar Cliente';

    protected static ?string $breadcrumb = 'Editar';

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()->label('Visualizar'),
            DeleteAction::make()
                ->label('Excluir')
                ->modalHeading('Excluir cliente')
                ->modalDescription('O cadastro deixa de aparecer na listagem, mas é preservado para manter o histórico comercial.'),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Cliente atualizado com sucesso.';
    }
}
