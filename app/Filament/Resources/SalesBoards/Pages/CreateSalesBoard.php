<?php

namespace App\Filament\Resources\SalesBoards\Pages;

use App\Filament\Resources\SalesBoards\SalesBoardResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSalesBoard extends CreateRecord
{
    protected static string $resource = SalesBoardResource::class;

    protected static ?string $title = 'Cadastrar Quadro de Vendas';

    protected static ?string $breadcrumb = 'Cadastrar';

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Quadro de vendas cadastrado com sucesso.';
    }
}
