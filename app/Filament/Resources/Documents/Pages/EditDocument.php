<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Filament\Resources\Documents\DocumentResource;
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
        if (! empty($data['file_path'])) {
            $disk = Storage::disk('public');
            $path = $data['file_path'];

            $data['file_name'] = $data['file_name'] ?? basename($path);
            $data['mime_type'] = $data['mime_type'] ?? $disk->mimeType($path);
            $data['file_size'] = $data['file_size'] ?? $disk->size($path);
        }

        $record = $this->record;

        if (! empty($data['is_published']) && ! $record->is_published) {
            $data['published_at'] = now();
            $data['published_by'] = auth()->id();
        }

        return $data;
    }
}
