<?php

namespace App\Filament\Resources\Nimbus\PortalUsers\Pages;

use App\Filament\Resources\Nimbus\PortalUsers\PortalUserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

class EditPortalUser extends EditRecord
{
    protected static string $resource = PortalUserResource::class;

    protected static ?string $title = 'Editar Usuário do Portal';

    protected static ?string $breadcrumb = 'Editar';

    protected Width|string|null $maxContentWidth = Width::Full;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-portal-user-form-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Atualize os dados cadastrais e o status de acesso do usuário no portal.';
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
