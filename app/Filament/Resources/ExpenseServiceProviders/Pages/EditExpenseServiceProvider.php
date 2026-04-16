<?php

namespace App\Filament\Resources\ExpenseServiceProviders\Pages;

use App\Filament\Resources\ExpenseServiceProviders\ExpenseServiceProviderResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditExpenseServiceProvider extends EditRecord
{
    protected static string $resource = ExpenseServiceProviderResource::class;

    protected static ?string $title = 'Editar prestador de serviço';

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Prestador de serviço atualizado com sucesso.';
    }
}
