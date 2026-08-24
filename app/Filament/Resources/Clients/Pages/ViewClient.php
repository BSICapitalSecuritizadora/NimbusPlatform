<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Models\Client;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\ViewRecord;

class ViewClient extends ViewRecord
{
    protected static string $resource = ClientResource::class;

    protected static ?string $title = 'Visualizar Cliente';

    protected static ?string $breadcrumb = 'Visualizar';

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label('Editar')
                ->visible(fn (Client $record): bool => ClientResource::canEdit($record)),
            RestoreAction::make()
                ->label('Restaurar Cadastro')
                ->icon('heroicon-o-arrow-uturn-left')
                ->modalHeading('Restaurar cliente')
                ->modalDescription('O mesmo cadastro volta para a listagem de clientes ativos, com todos os dados e contratos já vinculados. Nenhum cliente novo é criado.')
                ->modalSubmitActionLabel('Restaurar cadastro')
                ->successNotificationTitle('Cliente restaurado com sucesso.')
                ->visible(fn (Client $record): bool => ClientResource::canRestore($record)),
        ];
    }
}
