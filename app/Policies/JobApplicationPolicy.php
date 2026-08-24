<?php

namespace App\Policies;

use App\Enums\AccessPermission;
use App\Models\JobApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class JobApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(AccessPermission::RecruitmentApplicationsView->value);
    }

    public function view(User $user, Model $record): bool
    {
        return $user->can(AccessPermission::RecruitmentApplicationsView->value);
    }

    public function update(User $user, ?JobApplication $jobApplication = null): bool
    {
        return $user->can(AccessPermission::RecruitmentApplicationsUpdate->value);
    }

    public function delete(User $user, ?JobApplication $jobApplication = null): bool
    {
        return $user->can(AccessPermission::RecruitmentApplicationsDelete->value);
    }

    public function export(User $user): bool
    {
        return $user->can(AccessPermission::RecruitmentApplicationsView->value);
    }
}
