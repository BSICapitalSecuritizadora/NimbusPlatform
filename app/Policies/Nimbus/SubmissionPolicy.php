<?php

namespace App\Policies\Nimbus;

use App\Models\Nimbus\PortalUser;
use App\Models\Nimbus\Submission;
use App\Models\Nimbus\SubmissionFile;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;

class SubmissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super-admin', 'admin']);
    }

    public function view(User $user, Submission $submission): bool
    {
        return $user->hasAnyRole(['super-admin', 'admin']);
    }

    public function update(User $user, Submission $submission): bool
    {
        return $user->hasAnyRole(['super-admin', 'admin'])
            || $user->can('nimbus.submissions.update');
    }

    public function delete(User $user, Submission $submission): bool
    {
        return $user->hasAnyRole(['super-admin', 'admin'])
            || $user->can('nimbus.submissions.delete');
    }

    /**
     * Determine if the portal user can download the given submission file.
     * Covers ownership (IDOR prevention) and ADMIN-only visibility.
     * Returns 404 on denial to avoid leaking file existence.
     */
    public function downloadFile(Authenticatable $user, Submission $submission, SubmissionFile $file): Response
    {
        if ($user instanceof User) {
            if (! $user->hasAnyRole(['super-admin', 'admin']) && ! $user->can('nimbus.submissions.view')) {
                return Response::denyAsNotFound();
            }

            if ($file->nimbus_submission_id !== $submission->id) {
                return Response::denyAsNotFound();
            }

            return Response::allow();
        }

        if (! $user instanceof PortalUser) {
            return Response::denyAsNotFound();
        }

        if ($submission->nimbus_portal_user_id !== $user->id) {
            return Response::denyAsNotFound();
        }

        if ($file->nimbus_submission_id !== $submission->id) {
            return Response::denyAsNotFound();
        }

        if (($file->origin === 'ADMIN') && (! $file->visible_to_user)) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }
}
