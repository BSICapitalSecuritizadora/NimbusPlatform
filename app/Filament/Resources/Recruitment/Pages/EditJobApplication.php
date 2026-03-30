<?php

namespace App\Filament\Resources\Recruitment\Pages;

use App\Filament\Resources\Recruitment\JobApplicationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditJobApplication extends EditRecord
{
    protected static string $resource = JobApplicationResource::class;

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
        $statusChanged = $data['status'] !== $this->record->status;
        $notesChanged = ($data['internal_notes'] ?? null) !== $this->record->internal_notes;

        if ($statusChanged || $notesChanged) {
            $data['reviewed_at'] = now();
            $data['reviewed_by_user_id'] = auth()->id();
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
