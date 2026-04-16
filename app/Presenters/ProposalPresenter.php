<?php

namespace App\Presenters;

use App\Enums\ProposalStatus;
use App\Models\Proposal;

/**
 * Encapsulates presentation-specific logic for a Proposal, keeping the
 * Eloquent model free of display concerns.
 */
class ProposalPresenter
{
    public function __construct(private readonly Proposal $proposal) {}

    public function statusLabel(): string
    {
        return ProposalStatus::labelFor($this->proposal->status);
    }

    public function statusColor(): string
    {
        return ProposalStatus::colorFor($this->proposal->status);
    }

    public function companyAddress(): string
    {
        return $this->proposal->company?->full_address ?? '—';
    }
}
