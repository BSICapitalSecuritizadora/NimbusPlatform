<?php

namespace App\Observers;

use App\Actions\Emissions\ConsolidateInitialSalesBoards;
use App\Models\Emission;

/**
 * Guards the transition out of the "Em Elaboração" status.
 *
 * While the emission is being structured its sales boards are freely editable
 * and no initial position exists. Leaving that status consolidates the current
 * position of every construction -- and is blocked while any of them still has
 * no sales board.
 */
class EmissionObserver
{
    public function __construct(
        private readonly ConsolidateInitialSalesBoards $consolidateInitialSalesBoards,
    ) {}

    public function updating(Emission $emission): void
    {
        if (! $this->isLeavingDraft($emission->getOriginal('status'), $emission->status)) {
            return;
        }

        $this->consolidateInitialSalesBoards->assertReadyToConsolidate($emission);
    }

    public function updated(Emission $emission): void
    {
        if (! $emission->wasChanged('status')) {
            return;
        }

        if (! $this->isLeavingDraft($emission->getOriginal('status'), $emission->status)) {
            return;
        }

        $this->consolidateInitialSalesBoards->handle($emission);
    }

    private function isLeavingDraft(mixed $originalStatus, mixed $currentStatus): bool
    {
        return ($originalStatus === Emission::STATUS_DRAFT)
            && ($currentStatus !== Emission::STATUS_DRAFT);
    }
}
