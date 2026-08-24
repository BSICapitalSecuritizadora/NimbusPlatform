<?php

namespace App\Observers;

use App\Models\SalesBoard;
use Illuminate\Validation\ValidationException;

/**
 * Turns every sales board change into a new history version.
 *
 * Updates never overwrite the previous position: each recorded version stays in
 * the log so the whole evolution -- initial position, every change, current
 * position -- can be reconstructed for each construction.
 */
class SalesBoardObserver
{
    public function updating(SalesBoard $salesBoard): void
    {
        if (! $salesBoard->hasVersionableChanges()) {
            return;
        }

        if (! $salesBoard->requiresChangeReason()) {
            return;
        }

        if (filled($salesBoard->changeReason)) {
            return;
        }

        throw ValidationException::withMessages([
            'change_reason' => 'Já existe um registro do Quadro de Vendas para esta competência. Informe o motivo da alteração.',
        ]);
    }

    public function created(SalesBoard $salesBoard): void
    {
        $salesBoard->snapshotTrackedValues();

        $salesBoard->changeReason = null;
    }

    public function updated(SalesBoard $salesBoard): void
    {
        if (! $salesBoard->wasChanged(SalesBoard::versionedFields())) {
            return;
        }

        $salesBoard->snapshotTrackedValues();

        $salesBoard->changeReason = null;
    }
}
