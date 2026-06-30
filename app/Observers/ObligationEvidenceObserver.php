<?php

namespace App\Observers;

use App\Models\ObligationEvidence;
use Filament\Notifications\Notification;

class ObligationEvidenceObserver
{
    public function created(ObligationEvidence $evidence): void
    {
        // When an evidence is uploaded, notify the reviewer or people who can review
        // In this case, usually the "reviewer" is not set until it's reviewed.
        // We notify the emission's coordinator, or users with 'obligation_evidences.review' permission.
        // For simplicity, we can notify the responsible_user_id of the obligation if they are not the uploader,
        // or notify people who have permission to review it.
        // If we don't have a clear target, we skip or notify the obligation's responsible user.

        $obligation = $evidence->obligation;
        $responsible = $obligation->responsibleUser;

        if ($responsible && $responsible->id !== $evidence->uploaded_by) {
            Notification::make()
                ->title('Nova evidência enviada')
                ->body("Uma evidência para a obrigação '{$obligation->title}' aguarda revisão.")
                ->info()
                ->sendToDatabase($responsible);
        }
    }

    public function updated(ObligationEvidence $evidence): void
    {
        $obligation = $evidence->obligation;
        $uploader = $evidence->uploader;

        if ($evidence->wasChanged('status')) {
            if ($evidence->status === ObligationEvidence::STATUS_REJECTED) {
                if ($uploader) {
                    Notification::make()
                        ->title('Evidência rejeitada')
                        ->body("A evidência para a obrigação '{$obligation->title}' foi rejeitada. Motivo: {$evidence->rejection_reason}")
                        ->danger()
                        ->sendToDatabase($uploader);
                }
            } elseif ($evidence->status === ObligationEvidence::STATUS_APPROVED) {
                if ($uploader) {
                    Notification::make()
                        ->title('Evidência aprovada')
                        ->body("A evidência para a obrigação '{$obligation->title}' foi aprovada.")
                        ->success()
                        ->sendToDatabase($uploader);
                }
            }
        }
    }
}
