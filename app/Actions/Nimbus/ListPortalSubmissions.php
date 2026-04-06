<?php

namespace App\Actions\Nimbus;

use App\Models\Nimbus\PortalUser;
use App\Models\Nimbus\Submission;
use Illuminate\Database\Eloquent\Collection;

class ListPortalSubmissions
{
    /**
     * @return Collection<int, Submission>
     */
    public function handle(PortalUser $portalUser): Collection
    {
        return Submission::query()
            ->whereBelongsTo($portalUser, 'portalUser')
            ->orderByDesc('submitted_at')
            ->get();
    }
}
