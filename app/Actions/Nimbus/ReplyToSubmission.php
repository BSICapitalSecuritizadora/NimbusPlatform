<?php

namespace App\Actions\Nimbus;

use App\Http\Requests\Nimbus\StoreSubmissionReplyRequest;
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
        StoreSubmissionReplyRequest $request,
        Submission $submission,
        PortalUser $portalUser,
    ): Submission {
        abort_unless(
            $submission->nimbus_portal_user_id === $portalUser->id,
            Response::HTTP_FORBIDDEN,
            'Acesso negado.',
        );

        abort_unless($submission->status === Submission::STATUS_NEEDS_CORRECTION, Response::HTTP_FORBIDDEN);

        DB::transaction(function () use ($request, $submission, $portalUser): void {
            if ($request->hasFile('file')) {
                $this->storeSubmissionFile->handle(
                    submission: $submission,
                    file: $request->file('file'),
                    documentType: 'OTHER',
                    origin: 'USER',
                    visibleToUser: true,
                    uploadedByType: 'PORTAL_USER',
                    uploadedById: $portalUser->id,
                    notes: 'Arquivo enviado pelo solicitante em resposta a uma solicitação de correção.',
                    directory: "nimbus/submissions/{$submission->id}/corrections",
                );
            }

            $comment = trim((string) $request->input('comment'));

            if ($comment !== '') {
                $submission->notes()->create([
                    'user_id' => null,
                    'visibility' => 'ADMIN_ONLY',
                    'message' => $comment,
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
