<?php

namespace App\Filament\Resources\Constructions\Pages;

use App\Filament\Resources\Constructions\ConstructionResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateConstruction extends CreateRecord
{
    protected static string $resource = ConstructionResource::class;

    protected static ?string $title = 'Cadastrar obra';

    protected static ?string $breadcrumb = 'Criar';

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function preserveFormDataWhenCreatingAnother(array $data): array
    {
        return [
            'emission_id' => $data['emission_id'] ?? null,
        ];
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()
            ->label('Salvar e criar outro da mesma emissão');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Obra criada com sucesso.';
    }
}
