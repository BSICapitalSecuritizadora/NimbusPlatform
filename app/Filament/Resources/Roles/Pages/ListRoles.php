<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRoles extends ListRecords
{
    protected static string $resource = RoleResource::class;

    protected static ?string $title = 'Perfis de acesso';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-roles-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Gerencie os conjuntos de permissões utilizados para controlar o acesso dos usuários à plataforma.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Criar perfil de acesso')
                ->icon('heroicon-m-plus')
                ->color('primary'),
        ];
    }
}
