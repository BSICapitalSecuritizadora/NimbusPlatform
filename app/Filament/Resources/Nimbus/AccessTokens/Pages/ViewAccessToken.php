<?php

namespace App\Filament\Resources\Nimbus\AccessTokens\Pages;

use App\Filament\Resources\Nimbus\AccessTokens\AccessTokenResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;

class ViewAccessToken extends ViewRecord
{
    protected static string $resource = AccessTokenResource::class;

    protected static ?string $title = 'Detalhes da Chave de Acesso';

    protected static ?string $breadcrumb = 'Visualizar';

    protected Width|string|null $maxContentWidth = Width::Full;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-access-tokens-view-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Metadados de auditoria e status de utilização da chave de acesso no portal.';
    }

    protected function getHeaderActions(): array
    {
        return [
            AccessTokenResource::getRevokeAction(),
        ];
    }
}
