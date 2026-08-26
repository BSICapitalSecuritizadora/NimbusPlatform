<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use Filament\Resources\Pages\CreateRecord;

class CreateClient extends CreateRecord
{
    protected static string $resource = ClientResource::class;

    protected static ?string $title = 'Cadastrar Cliente';

    protected static ?string $breadcrumb = 'Cadastrar';

    /**
     * @var array<string, mixed>
     */
    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page',
    ];

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Cliente cadastrado com sucesso.';
    }
}
