<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected static ?string $title = 'Criar Perfil de Acesso';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-role-create-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Crie um novo perfil e configure suas permissões na plataforma.';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['guard_name'] = 'web';

        return $data;
    }
}
