<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Filament\Resources\Documents\DocumentResource;
use App\Models\Document;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDocument extends EditRecord
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->record;

        $data['storage_disk'] = $data['storage_disk'] ?? $record->storage_disk ?? Document::defaultStorageDisk();

        if (is_array($data['file_path'] ?? null)) {
            $data['file_path'] = reset($data['file_path']) ?: null;
        }

        // Ver CreateDocument: os metadados do arquivo são derivados do disco no
        // `saving`, e só o nome exibido continua vindo do formulário.
        if (! empty($data['file_path'])) {
            $data['file_name'] = ($data['file_name'] ?? null) ?: basename((string) $data['file_path']);
        }

        if (! empty($data['is_published']) && ! $record->is_published) {
            $data['published_at'] = now();
            $data['published_by'] = auth()->id();
        }

        return $data;
    }
}
