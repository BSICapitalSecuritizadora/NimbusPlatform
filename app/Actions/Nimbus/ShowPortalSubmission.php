<?php

namespace App\Actions\Nimbus;

use App\Models\Nimbus\PortalUser;
use App\Models\Nimbus\Submission;
use Symfony\Component\HttpFoundation\Response;

class ShowPortalSubmission
{
    public function handle(Submission $submission, PortalUser $portalUser): Submission
    {
        abort_unless(
            $submission->nimbus_portal_user_id === $portalUser->id,
            Response::HTTP_NOT_FOUND,
        );

        $submission->loadMissing([
            'shareholders',
            'userUploadedFiles',
            'portalVisibleResponseFiles',
            'portalVisibleNotes',
        ]);

        return $submission;
    }
}
