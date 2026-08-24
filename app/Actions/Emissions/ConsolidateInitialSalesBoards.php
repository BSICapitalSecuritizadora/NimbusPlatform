<?php

namespace App\Actions\Emissions;

use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Freezes the sales board position of every construction of an emission at the
 * moment it leaves the "Em Elaboração" status.
 *
 * The position is stored as a flagged entry in the existing sales board history
 * table, so no structure is duplicated: from then on the current sales board
 * keeps being updated monthly while the consolidation entry stays untouched.
 */
class ConsolidateInitialSalesBoards
{
    /**
     * Constructions of the emission that still have no sales board, and
     * therefore no position to consolidate.
     *
     * @return Collection<int, Construction>
     */
    public function pendingConstructions(Emission $emission): Collection
    {
        return $emission->constructions()
            ->whereDoesntHave('salesBoards')
            ->orderBy('development_name')
            ->get();
    }

    /**
     * @throws ValidationException When any construction has no sales board yet.
     */
    public function assertReadyToConsolidate(Emission $emission): void
    {
        $pendingConstructions = $this->pendingConstructions($emission);

        if ($pendingConstructions->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => sprintf(
                'Não é possível alterar o status da emissão. Existem empreendimentos sem o Quadro de Vendas preenchido: %s.',
                $pendingConstructions->pluck('development_name')->implode(', '),
            ),
        ]);
    }

    /**
     * Marks the position in force as the initial position of every sales board
     * that has not been consolidated yet.
     *
     * The history log already holds a version for the current position, so the
     * consolidation flags that entry instead of duplicating it. Boards already
     * holding an initial position are skipped, so a second status change never
     * replaces the original consolidation.
     *
     * @return int Number of positions consolidated.
     */
    public function handle(Emission $emission): int
    {
        return DB::transaction(function () use ($emission): int {
            $salesBoards = $emission->salesBoards()
                ->whereDoesntHave('valueHistories', fn ($query) => $query->where('is_initial', true))
                ->get();

            $salesBoards->each(fn (SalesBoard $salesBoard) => $this->consolidate($salesBoard));

            return $salesBoards->count();
        });
    }

    private function consolidate(SalesBoard $salesBoard): void
    {
        $currentVersion = $salesBoard->currentVersion()->first();

        // Boards registered before the history log existed have no version to
        // flag, so the position in force is recorded as the initial one.
        if ($currentVersion === null) {
            $salesBoard->snapshotTrackedValues(asInitialPosition: true);

            return;
        }

        $currentVersion->update(['is_initial' => true]);
    }
}
