<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Filament\Resources\Documents\DocumentResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Storage;

class CreateDocument extends CreateRecord
{
    protected static string $resource = DocumentResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! empty($data['file_path'])) {
            $disk = Storage::disk('public');
            $path = $data['file_path'];

            $data['file_name'] = $data['file_name'] ?? basename($path);
            $data['mime_type'] = $data['mime_type'] ?? $disk->mimeType($path);
            $data['file_size'] = $data['file_size'] ?? $disk->size($path);
        }

        if (! empty($data['is_published'])) {
            $data['published_at'] = $data['published_at'] ?? now();
            $data['published_by'] = $data['published_by'] ?? auth()->id();
        }

        return $data;
    }
}
