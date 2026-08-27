<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The end of the single-buyer model.
     *
     * `contracts.client_id` held the one buyer a contract was allowed to have.
     * It stopped being the truth two releases ago -- the buyers are the rows of
     * `contract_clients` -- and has since been nullable, unread and unwritten.
     * Removing it is what makes the old shape unreachable: from here on, code
     * cannot fall back to a single buyer, because there is nowhere to fall back
     * to.
     *
     * The three changes go in one blueprint, and that is the whole trick.
     * SQLite has no statement that drops a foreign key, so `dropForeign` is in
     * {@see SQLiteGrammar::getAlterCommands()}
     * -- Laravel answers it by rebuilding the table, and the rebuild is what
     * carries the column away. Asking for the column on its own would instead
     * compile to the native `ALTER TABLE ... DROP COLUMN`, which SQLite refuses
     * while a foreign key still names the column. MySQL applies the same three
     * changes in one ALTER, in the order listed: the key first, because the
     * index it leans on -- `contracts_client_id_sale_date_index`, there is no
     * standalone one -- cannot be dropped while the key needs it.
     *
     * `occupied_unit_lock` is untouched. Its expression reads `status`,
     * `deleted_at` and `construction_unit_id`, never the buyer: how many buyers
     * a contract has has never had anything to do with which contract holds a
     * unit.
     */
    public function up(): void
    {
        $this->guardAgainstContractsWithoutBuyers();

        $this->withPreservedBuyerLinks(function (): void {
            Schema::table('contracts', function (Blueprint $table) {
                $table->dropForeign(['client_id']);
                $table->dropIndex(['client_id', 'sale_date']);
                $table->dropColumn('client_id');
            });
        });
    }

    /**
     * Runs a change to `contracts` without losing the buyers of the contracts.
     *
     * SQLite has no in-place ALTER for any of this, so Laravel rebuilds the
     * table: it copies `contracts` into a new one and drops the old. Dropping it
     * behaves as a delete of every row, which fires the cascade on
     * `contract_clients.contract_id` -- and every buyer link in the database
     * disappears while the contracts themselves survive the copy. Measured, not
     * feared: a pivot with one row came back with none.
     *
     * `PRAGMA foreign_keys` is not the lever it looks like. SQLite ignores it
     * inside a transaction, and migrations run inside one, so
     * `withoutForeignKeyConstraints()` changes nothing here -- also measured.
     *
     * So the links are read before and put back after, ids included, if the
     * table came back smaller. On MySQL the ALTER happens in place, nothing is
     * lost and the restore never runs; the snapshot costs one query over a table
     * that holds one row per buyer.
     */
    private function withPreservedBuyerLinks(callable $change): void
    {
        $links = DB::table('contract_clients')->orderBy('id')->get();

        $change();

        if (DB::table('contract_clients')->count() >= $links->count()) {
            return;
        }

        $links->chunk(500)->each(function ($chunk): void {
            DB::table('contract_clients')->insertOrIgnore(
                $chunk->map(fn (object $link): array => (array) $link)->all(),
            );
        });
    }

    /**
     * Refuses to remove the column while it is still the only place some
     * contract records a buyer.
     *
     * The backfill belonged to the release that created `contract_clients`; this
     * one only asks whether it is complete. A contract with no row there would
     * lose its buyer for good the moment the column goes, so the migration stops
     * and says how many -- it does not quietly re-run a backfill, which would
     * hide the fact that something went wrong two releases earlier.
     *
     * Runs before any DDL, so an abort leaves the schema exactly as it was.
     */
    private function guardAgainstContractsWithoutBuyers(): void
    {
        $orphans = DB::table('contracts')
            ->leftJoin('contract_clients', 'contract_clients.contract_id', '=', 'contracts.id')
            ->whereNull('contract_clients.id')
            ->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "Remoção abortada: {$orphans} contrato(s) não possuem comprador em contract_clients. "
                .'A coluna contracts.client_id ainda é a única referência de comprador deles.'
            );
        }
    }

    /**
     * Rebuilds the column, the key and the index -- empty.
     *
     * The schema comes back; the meaning does not. A contract with two buyers
     * has no single value to put here, and choosing one would invent a main
     * buyer the domain never had. So every row comes back NULL, and the code of
     * the previous release would read every contract as having no buyer at all.
     *
     * In other words: rolling this back restores the shape of the table, not the
     * single-buyer domain. Returning to that domain stopped being possible the
     * moment a contract had two buyers -- which is exactly why leaving it took
     * three releases.
     */
    public function down(): void
    {
        $this->withPreservedBuyerLinks(function (): void {
            Schema::table('contracts', function (Blueprint $table) {
                $table->foreignId('client_id')->nullable()->after('id')->constrained()->restrictOnDelete();
                $table->index(['client_id', 'sale_date']);
            });
        });
    }
};
