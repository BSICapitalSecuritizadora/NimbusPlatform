<?php

namespace App\Filament\Resources\SalesBoards\Pages;

use App\Filament\Resources\SalesBoards\SalesBoardResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSalesBoard extends ViewRecord
{
    protected static string $resource = SalesBoardResource::class;

    protected static ?string $title = 'Visualizar Quadro de Vendas';

    protected static ?string $breadcrumb = 'Visualizar';

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
