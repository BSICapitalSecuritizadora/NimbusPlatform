<?php

namespace App\Policies\Nimbus;

use App\Models\Nimbus\PortalUser;
use App\Models\Nimbus\Submission;
use App\Models\Nimbus\SubmissionFile;
use Illuminate\Contracts\Auth\Authenticatable;

class SubmissionPolicy
{
    /**
     * Determine if the portal user can download the given submission file.
     * Covers ownership (IDOR prevention) and ADMIN-only visibility.
     */
    public function downloadFile(Authenticatable $user, Submission $submission, SubmissionFile $file): bool
    {
        if (! $user instanceof PortalUser) {
            return false;
        }

        if ($submission->nimbus_portal_user_id !== $user->id) {
            return false;
        }

        if ($file->nimbus_submission_id !== $submission->id) {
            return false;
        }

        if (($file->origin === 'ADMIN') && (! $file->visible_to_user)) {
            return false;
        }

        return true;
    }
}
