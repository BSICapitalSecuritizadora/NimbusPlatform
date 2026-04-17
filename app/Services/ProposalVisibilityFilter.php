<?php

namespace App\Services;

use App\Models\ProposalRepresentative;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Centralizes the visibility logic that determines which proposals a given
 * user is allowed to query. Super-admins and admins see all proposals;
 * regular users only see proposals assigned to their representative record.
 */
class ProposalVisibilityFilter
{
    public static function apply(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasAnyRole(['super-admin', 'admin'])) {
            return $query;
        }

        return $query->whereIn(
            'assigned_representative_id',
            ProposalRepresentative::query()->select('id')->where('user_id', $user->id),
        );
    }
}
