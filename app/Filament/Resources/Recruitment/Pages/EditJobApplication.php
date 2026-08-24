<?php

namespace App\Filament\Resources\Recruitment\Pages;

use App\Filament\Resources\Recruitment\JobApplicationResource;
use App\Filament\Resources\Recruitment\RelationManagers\JobApplicationStatusHistoryRelationManager;
use App\Jobs\SendJobApplicationStatusMail;
use App\Models\JobApplication;
use App\Models\JobApplicationStatusHistory;
use App\Services\Recruitment\JobApplicationStatusService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditJobApplication extends EditRecord
{
    protected static string $resource = JobApplicationResource::class;

    protected static ?string $title = 'Avaliar Candidatura';

    protected static ?string $breadcrumb = 'Avaliar';

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->label('Excluir Candidatura')
                ->modalHeading('Excluir Candidatura'),
        ];
    }

    public function getRelationManagers(): array
    {
        return [
            JobApplicationStatusHistoryRelationManager::class,
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

    protected function afterSave(): void
    {
        $originalStatus = $this->record->getOriginal('status');
        $newStatus = $this->record->status;

        if ($originalStatus !== $newStatus) {
            JobApplicationStatusHistory::create([
                'job_application_id' => $this->record->id,
                'from_status' => $originalStatus,
                'to_status' => $newStatus,
                'changed_by_user_id' => auth()->id(),
                'note' => null,
            ]);

            if (in_array($newStatus, [JobApplication::STATUS_HIRED, JobApplication::STATUS_REJECTED], true)) {
                SendJobApplicationStatusMail::dispatch($this->record->fresh(['vacancy']));
            }
        }

        if ($this->record->statusHistories()->count() === 0) {
            JobApplicationStatusService::recordInitialHistory($this->record);
        }
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Candidatura avaliada com sucesso.';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
