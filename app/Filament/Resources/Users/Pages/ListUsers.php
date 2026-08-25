<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected static ?string $title = 'Usuários';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-users-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Gerencie usuários, vínculos organizacionais, perfis e status de acesso à plataforma.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Criar usuário')
                ->icon('heroicon-m-plus')
                ->color('primary'),
        ];
    }
}
