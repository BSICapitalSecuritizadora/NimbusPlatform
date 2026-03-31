<?php

namespace App\Filament\Resources\Nimbus\AccessTokens\Pages;

use App\Filament\Resources\Nimbus\AccessTokens\AccessTokenResource;
use Filament\Resources\Pages\ListRecords;

class ListAccessTokens extends ListRecords
{
    protected static string $resource = AccessTokenResource::class;

    protected static ?string $title = 'Chaves de Acesso';

    protected static ?string $breadcrumb = 'Listar';

    protected function getHeaderActions(): array
    {
        return [];
    }
}
