<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\Investor;
use App\Models\User;

class DocumentPolicy
{
    /**
     * Determine whether the administrative user can view the model in Filament.
     */
    public function view(User $user, Document $document): bool
    {
        return $user->hasAnyRole(['super-admin', 'admin']) || $user->can('documents.view');
    }

    /**
     * Determine whether the investor can download the document in the portal.
     */
    public function download(Investor $investor, Document $document): bool
    {
        return Document::query()
            ->whereKey($document->id)
            ->visibleToInvestor($investor->id)
            ->exists();
    }
}
