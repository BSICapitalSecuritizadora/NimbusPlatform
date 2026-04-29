<?php

namespace App\Filament\Resources\SalesBoards\Pages;

use App\Filament\Resources\SalesBoards\SalesBoardResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSalesBoard extends EditRecord
{
    protected static string $resource = SalesBoardResource::class;

    protected static ?string $title = 'Editar quadro de vendas';

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Quadro de vendas atualizado com sucesso.';
    }
}
