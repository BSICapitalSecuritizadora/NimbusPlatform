<?php

namespace App\Filament\Resources\Funds\Pages;

use App\Filament\Resources\Funds\FundResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFund extends CreateRecord
{
    protected static string $resource = FundResource::class;

    protected static ?string $title = 'Criar fundo';

    protected static ?string $breadcrumb = 'Criar';

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Fundo criado com sucesso.';
    }
}
