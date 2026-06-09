<?php

namespace App\Filament\Resources\Operations\Pages;

use App\Filament\Resources\Operations\OperationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOperation extends CreateRecord
{
    protected static string $resource = OperationResource::class;

    /**
     * @var array<int, array<string, mixed>>
     */
    protected array $developments = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->developments = $data['developments'] ?? [];
        unset($data['developments']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->syncDevelopmentPlans($this->developments);
    }
}
