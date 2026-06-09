<?php

namespace App\Filament\Resources\Negotiations\Pages;

use App\Filament\Resources\Negotiations\NegotiationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateNegotiation extends CreateRecord
{
    protected static string $resource = NegotiationResource::class;

    protected static ?string $title = 'Cadastrar Negociação';

    protected static ?string $breadcrumb = 'Cadastrar';

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Negociação cadastrada com sucesso.';
    }
}
