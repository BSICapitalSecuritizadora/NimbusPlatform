<?php

namespace App\Actions\Nimbus;

use App\DTOs\Nimbus\SubmissionReplyDTO;
use App\Models\Nimbus\PortalUser;
use App\Models\Nimbus\Submission;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ReplyToSubmission
{
    public function __construct(
        protected StoreSubmissionFile $storeSubmissionFile,
    ) {}

    public function handle(
        SubmissionReplyDTO $dto,
        Submission $submission,
        PortalUser $portalUser,
    ): Submission {
        abort_unless(
            $submission->nimbus_portal_user_id === $portalUser->id,
            Response::HTTP_FORBIDDEN,
            'Acesso negado.',
        );

        abort_unless($submission->status === Submission::STATUS_NEEDS_CORRECTION, Response::HTTP_FORBIDDEN);

        DB::transaction(function () use ($dto, $submission, $portalUser): void {
            if ($dto->file !== null) {
                $this->storeSubmissionFile->handle(
                    submission: $submission,
                    file: $dto->file,
                    documentType: 'OTHER',
                    origin: 'USER',
                    visibleToUser: true,
                    uploadedByType: 'PORTAL_USER',
                    uploadedById: $portalUser->id,
                    notes: 'Arquivo enviado pelo solicitante em resposta a uma solicitação de correção.',
                    directory: "submissions/{$submission->id}/corrections",
                );
            }

            if ($dto->comment !== null) {
                $submission->notes()->create([
                    'user_id' => null,
                    'visibility' => 'ADMIN_ONLY',
                    'message' => $dto->comment,
                ]);
            }

            $submission->update([
                'status' => Submission::STATUS_UNDER_REVIEW,
                'status_updated_at' => now(),
                'status_updated_by' => null,
            ]);
        });

        return $submission->refresh();
    }
}
