<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Filament\Resources\Documents\DocumentResource;
use App\Models\Document;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateDocument extends CreateRecord
{
    protected static string $resource = DocumentResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['storage_disk'] = $data['storage_disk'] ?? Document::defaultStorageDisk();

        if (is_array($data['file_path'] ?? null)) {
            $data['file_path'] = reset($data['file_path']) ?: null;
        }

        if (empty($data['file_path'])) {
            throw ValidationException::withMessages([
                'file_path' => 'O caminho final do arquivo não foi gerado. Tente enviar o arquivo novamente.',
            ]);
        }

        // `mime_type` e `file_size` são derivados do arquivo em disco por
        // DerivesStoredFileMetadata e não chegam mais no payload do formulário.
        // O nome exibido continua vindo do upload: o trait não o deriva neste
        // model, para não trocá-lo pelo nome de armazenamento.
        $data['file_name'] = ($data['file_name'] ?? null) ?: basename((string) $data['file_path']);

        if (! empty($data['is_published'])) {
            $data['published_at'] = $data['published_at'] ?? now();
            $data['published_by'] = $data['published_by'] ?? auth()->id();
        }

        return $data;
    }
}
