<?php

namespace App\Actions\Nimbus;

use App\Models\Nimbus\PortalUser;
use App\Models\Nimbus\Submission;
use App\Models\Nimbus\SubmissionFile;
use App\Services\DocumentStorageService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadPortalSubmissionFile
{
    public function __construct(
        protected DocumentStorageService $documentStorageService,
    ) {}

    public function handle(Submission $submission, SubmissionFile $file, PortalUser $portalUser): StreamedResponse
    {
        abort_unless(
            $submission->nimbus_portal_user_id === $portalUser->id,
            Response::HTTP_FORBIDDEN,
            'Acesso negado.',
        );

        abort_unless($file->nimbus_submission_id === $submission->id, Response::HTTP_NOT_FOUND);

        if (($file->origin === 'ADMIN') && (! $file->visible_to_user)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        if (! $this->documentStorageService->privateExists($file->storage_path)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $this->documentStorageService->downloadPrivate($file->storage_path, $file->original_name);
    }
}
