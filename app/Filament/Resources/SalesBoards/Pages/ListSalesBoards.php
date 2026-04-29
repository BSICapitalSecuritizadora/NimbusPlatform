<?php

namespace App\Filament\Resources\SalesBoards\Pages;

use App\Filament\Resources\SalesBoards\SalesBoardResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSalesBoards extends ListRecords
{
    protected static string $resource = SalesBoardResource::class;

    protected static ?string $title = 'Quadro de Vendas';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Criar quadro de vendas'),
        ];
    }
}
