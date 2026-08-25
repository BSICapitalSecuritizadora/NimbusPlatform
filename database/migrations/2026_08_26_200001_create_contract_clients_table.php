<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The buyers of a contract.
     *
     * A sale can be made to more than one person -- a couple, a company and its
     * partner -- and `contracts.client_id` can only ever name one of them. This
     * table is what lets the platform stop losing the others.
     *
     * The unit is not involved: a buyer never holds a unit directly. The chain
     * stays `Client -> Contract -> ConstructionUnit`, so `occupied_unit_lock`,
     * distrato and revenda keep working exactly as they do, whatever the number
     * of buyers.
     *
     * Expand phase: this table is created and filled, and `contracts.client_id`
     * stays where it is. The application still writes the single buyer through
     * the old column and mirrors it here, so the two cannot drift while both
     * exist. The switch to reading from here, and the multi-buyer domain, come
     * in the following release -- by which time this table already exists in
     * production, which is what removes the window where new code would query a
     * table the migration had not created yet.
     */
    public function up(): void
    {
        Schema::create('contract_clients', function (Blueprint $table) {
            $table->id();

            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();

            /**
             * Restricted on delete, exactly as `contracts.client_id` is today: a
             * client that appears in the history of any contract cannot be force
             * deleted out from under it. Archiving (soft delete) stays allowed --
             * the link survives it, which is what keeps a historical contract
             * readable.
             */
            $table->foreignId('client_id')->constrained()->restrictOnDelete();

            /** One buyer counts once per contract, however many rows a spreadsheet repeats. */
            $table->unique(['contract_id', 'client_id']);
        });

        $this->backfill();
    }

    /**
     * Copies the single buyer each contract already has.
     *
     * Deterministic on purpose: the source is `contracts.client_id` and nothing
     * else -- no name matching, no `MIN(id)`, no "first row wins". Every contract
     * on record today has exactly one buyer, so the translation is exact rather
     * than a choice.
     *
     * Anything unexpected aborts the migration instead of being papered over. A
     * partially correct buyer table is worse than no buyer table: the next
     * release will treat it as the truth.
     */
    private function backfill(): void
    {
        $contracts = DB::table('contracts')->count();

        $withoutClient = DB::table('contracts')->whereNull('client_id')->count();

        if ($withoutClient > 0) {
            throw new RuntimeException("Backfill abortado: {$withoutClient} contrato(s) sem client_id.");
        }

        $orphans = DB::table('contracts')
            ->leftJoin('clients', 'clients.id', '=', 'contracts.client_id')
            ->whereNull('clients.id')
            ->count();

        if ($orphans > 0) {
            throw new RuntimeException("Backfill abortado: {$orphans} contrato(s) apontam para um cliente inexistente.");
        }

        DB::table('contracts')
            ->select('id', 'client_id')
            ->orderBy('id')
            ->chunkById(500, function ($chunk): void {
                DB::table('contract_clients')->insert(
                    $chunk->map(fn ($contract): array => [
                        'contract_id' => $contract->id,
                        'client_id' => $contract->client_id,
                    ])->all(),
                );
            });

        $linked = DB::table('contract_clients')->distinct()->count('contract_id');

        if ($linked !== $contracts) {
            throw new RuntimeException(
                "Backfill abortado: {$contracts} contrato(s) existem, mas {$linked} receberam comprador."
            );
        }
    }

    /**
     * Reversible while it is only a mirror: `contracts.client_id` still holds
     * every link this table holds, so dropping it loses nothing.
     *
     * That stops being true once the domain goes plural, which is precisely why
     * removing `contracts.client_id` is a separate, later release.
     */
    public function down(): void
    {
        Schema::dropIfExists('contract_clients');
    }
};
