<?php

namespace App\Filament\Resources\SalesBoards\Pages;

use App\Filament\Resources\SalesBoards\SalesBoardResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSalesBoard extends CreateRecord
{
    protected static string $resource = SalesBoardResource::class;

    protected static ?string $title = 'Criar quadro de vendas';

    protected static ?string $breadcrumb = 'Criar';

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Quadro de vendas criado com sucesso.';
    }
}
