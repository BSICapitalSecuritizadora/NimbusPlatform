<?php

namespace App\Filament\Resources\Nimbus\AccessTokens\Pages;

use App\Filament\Resources\Nimbus\AccessTokens\AccessTokenResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAccessToken extends ViewRecord
{
    protected static string $resource = AccessTokenResource::class;

    protected static ?string $title = 'Detalhes da chave de acesso';

    protected static ?string $breadcrumb = 'Visualizar';

    protected function getHeaderActions(): array
    {
        return [
            AccessTokenResource::getRevokeAction(),
        ];
    }
}
