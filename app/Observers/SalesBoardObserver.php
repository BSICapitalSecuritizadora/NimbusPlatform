<?php

namespace App\Observers;

use App\Models\SalesBoard;
use App\Services\SalesBoards\SalesBoardWriteGuard;
use Illuminate\Validation\ValidationException;

/**
 * Turns every sales board change into a new history version.
 *
 * Updates never overwrite the previous position: each recorded version stays in
 * the log so the whole evolution -- initial position, every change, current
 * position -- can be reconstructed for each construction.
 *
 * A partir do rollout por Emissão, é também aqui que a escrita manual é barrada
 * numa competência já automatizada. O guard vive no observer, e não na tela,
 * porque comando, job e importação escrevem pelo mesmo caminho -- e porque
 * silenciar os eventos para contornar o guard desligaria justamente o
 * versionamento acima.
 */
class SalesBoardObserver
{
    public function __construct(
        private readonly SalesBoardWriteGuard $writeGuard,
    ) {}

    public function creating(SalesBoard $salesBoard): void
    {
        $this->writeGuard->assertCanWrite($salesBoard);
    }

    public function deleting(SalesBoard $salesBoard): void
    {
        $this->writeGuard->assertCanDelete($salesBoard);
    }

    public function updating(SalesBoard $salesBoard): void
    {
        $this->writeGuard->assertCanWrite($salesBoard);

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
