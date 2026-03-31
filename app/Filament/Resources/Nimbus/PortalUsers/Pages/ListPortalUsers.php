<?php

namespace App\Filament\Resources\Nimbus\PortalUsers\Pages;

use App\Filament\Resources\Nimbus\PortalUsers\PortalUserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPortalUsers extends ListRecords
{
    protected static string $resource = PortalUserResource::class;

    protected static ?string $title = 'Usuários do Portal';

    protected static ?string $breadcrumb = 'Listar';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Novo usuário'),
        ];
    }
}
