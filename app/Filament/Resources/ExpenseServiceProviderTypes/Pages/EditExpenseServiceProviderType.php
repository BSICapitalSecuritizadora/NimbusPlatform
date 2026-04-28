<?php

namespace App\Filament\Resources\ExpenseServiceProviderTypes\Pages;

use App\Filament\Resources\ExpenseServiceProviderTypes\ExpenseServiceProviderTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditExpenseServiceProviderType extends EditRecord
{
    protected static string $resource = ExpenseServiceProviderTypeResource::class;

    protected static ?string $title = 'Editar tipo de prestador de serviço';

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Tipo de prestador de serviço atualizado com sucesso.';
    }
}
