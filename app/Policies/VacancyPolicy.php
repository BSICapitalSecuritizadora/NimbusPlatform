<?php

namespace App\Policies;

use App\Enums\AccessPermission;
use App\Models\User;
use App\Models\Vacancy;
use Illuminate\Database\Eloquent\Model;

class VacancyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(AccessPermission::RecruitmentVacanciesView->value);
    }

    public function view(User $user, Model $record): bool
    {
        return $user->can(AccessPermission::RecruitmentVacanciesView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(AccessPermission::RecruitmentVacanciesCreate->value);
    }

    public function update(User $user, ?Vacancy $vacancy = null): bool
    {
        return $user->can(AccessPermission::RecruitmentVacanciesUpdate->value);
    }

    public function delete(User $user, ?Vacancy $vacancy = null): bool
    {
        return $user->can(AccessPermission::RecruitmentVacanciesDelete->value);
    }

    public function replicate(User $user, ?Vacancy $vacancy = null): bool
    {
        return $user->can(AccessPermission::RecruitmentVacanciesCreate->value);
    }
}
