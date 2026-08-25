<?php

namespace App\Actions\Nimbus;

use App\DTOs\Nimbus\StoreSubmissionFileDTO;
use App\DTOs\Nimbus\SubmissionReplyDTO;
use App\Models\Nimbus\PortalUser;
use App\Models\Nimbus\Submission;
use App\Services\Nimbus\NimbusNotificationService;
use App\Services\Nimbus\SubmissionWorkflowService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ReplyToSubmission
{
    public function __construct(
        protected StoreSubmissionFile $storeSubmissionFile,
        protected SubmissionWorkflowService $workflowService,
        protected NimbusNotificationService $notificationService,
    ) {}

    public function handle(
        SubmissionReplyDTO $dto,
        Submission $submission,
        PortalUser $portalUser,
    ): Submission {
        abort_unless(
            $submission->nimbus_portal_user_id === $portalUser->id,
            Response::HTTP_NOT_FOUND,
        );

        abort_unless($submission->status === Submission::STATUS_NEEDS_CORRECTION, Response::HTTP_FORBIDDEN);

        DB::transaction(function () use ($dto, $submission, $portalUser): void {
            if ($dto->file !== null) {
                $this->storeSubmissionFile->handle($submission, new StoreSubmissionFileDTO(
                    file: $dto->file,
                    documentType: 'OTHER',
                    origin: 'USER',
                    visibleToUser: true,
                    uploadedByType: 'PORTAL_USER',
                    uploadedById: $portalUser->id,
                    notes: 'Arquivo enviado pelo solicitante em resposta a uma solicitação de correção.',
                    directory: "submissions/{$submission->id}/corrections",
                ));

                activity('nimbus')
                    ->performedOn($submission)
                    ->causedBy($portalUser)
                    ->withProperties([
                        'file_type' => 'OTHER',
                        'origin' => 'USER',
                    ])
                    ->log('nimbus.submission.correction_attachment');
            }

            if ($dto->comment !== null) {
                $submission->notes()->create([
                    'user_id' => null,
                    'visibility' => 'ADMIN_ONLY',
                    'message' => $dto->comment,
                ]);

                activity('nimbus')
                    ->performedOn($submission)
                    ->causedBy($portalUser)
                    ->withProperties([
                        'visibility' => 'ADMIN_ONLY',
                    ])
                    ->log('nimbus.submission.correction_response');
            }

            // Centralized workflow transition ensures history + audit.
            $this->workflowService->transition(
                $submission->refresh(),
                Submission::STATUS_UNDER_REVIEW,
                $portalUser,
                $dto->comment,
            );
        });

        try {
            $fresh = $submission->refresh();
            $historyId = $fresh->statusHistories()->where('old_status', Submission::STATUS_NEEDS_CORRECTION)->where('new_status', Submission::STATUS_UNDER_REVIEW)->reorder()->orderByDesc('id')->first()?->id;
            $this->notificationService->enqueueCorrectionResponseReceived($fresh, $historyId);
        } catch (\Throwable $e) {
            // Do not fail reply on notification error.
        }

        return $submission->refresh();
    }
}
