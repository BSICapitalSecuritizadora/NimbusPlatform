<?php

namespace App\Services;

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

        $representativeId = $user->proposalRepresentative?->id;

        if (! $representativeId) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('assigned_representative_id', $representativeId);
    }
}
