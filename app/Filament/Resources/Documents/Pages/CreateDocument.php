<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Filament\Resources\Documents\DocumentResource;
use App\Models\Document;
use App\Services\DocumentStorageService;
use Filament\Resources\Pages\CreateRecord;

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

        if (! empty($data['file_path'])) {
            $metadata = app(DocumentStorageService::class)->metadata(
                $data['file_path'],
                $data['storage_disk'],
            );

            $data['file_name'] = $data['file_name'] ?? basename($data['file_path']);
            $data['mime_type'] = $data['mime_type'] ?? $metadata['mime_type'];
            $data['file_size'] = $data['file_size'] ?? $metadata['size_bytes'];
        }

        if (! empty($data['is_published'])) {
            $data['published_at'] = $data['published_at'] ?? now();
            $data['published_by'] = $data['published_by'] ?? auth()->id();
        }

        return $data;
    }
}
