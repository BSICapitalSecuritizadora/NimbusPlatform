<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Filament\Resources\Documents\DocumentResource;
use App\Models\Document;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Storage;

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

        if (! empty($data['file_path'])) {
            $disk = Storage::disk($data['storage_disk']);

            $data['file_name'] = $data['file_name'] ?: basename((string) $data['file_path']);
            $data['mime_type'] = $data['mime_type'] ?: rescue(fn () => $disk->mimeType($data['file_path']), 'application/octet-stream');
            $data['file_size'] = $data['file_size'] ?: rescue(fn () => $disk->size($data['file_path']), 0);
        }

        if (! empty($data['is_published']) && ! $record->is_published) {
            $data['published_at'] = now();
            $data['published_by'] = auth()->id();
        }

        return $data;
    }
}
