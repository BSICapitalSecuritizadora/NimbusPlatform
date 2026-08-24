<?php

use App\Models\Contract;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the identity of a contract code off the database collation and into the
 * application, the same way the installment number already works.
 *
 * Before this, "A606" and "a606" were the same contract in MySQL -- where the
 * utf8mb4_unicode_ci collation folds case *and* accents -- and two different
 * contracts in the SQLite the test suite runs on. The rule a developer read in a
 * passing test was not the rule production enforced.
 *
 * After this, `code` is what the incorporadora wrote and `code_normalized` is
 * what uniqueness compares, computed by
 * {@see Contract::normalizeCodeForComparison()} and stored in a binary column so
 * both engines answer identically.
 *
 * Written defensively even though the codes were surveyed first: the column is
 * filled and every possible collision is looked for *before* the unique index is
 * created, and anything unexpected stops the migration with the conflicting rows
 * named. Nothing here edits or removes a contract -- a contract code is
 * commercial history, and resolving a clash is a decision for a person.
 */
return new class extends Migration
{
    private const CHUNK_SIZE = 500;

    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('contracts', function (Blueprint $table) use ($driver) {
            /**
             * Wider than `code`: uppercasing can lengthen a string (ß becomes
             * SS), so the derived value needs headroom the original does not.
             *
             * Created with a default so it can be added to a populated table
             * without a nullable phase and a later `change()` -- which on SQLite
             * would rebuild a table that carries a stored generated column.
             */
            $column = $table->string('code_normalized', 150)->default('')->after('code');

            /**
             * Binary collation, which is the other half of the fix. Normalizing
             * in PHP settles the casing, but a unique index still compares under
             * the column's collation, and the default here folds accents as
             * well. Byte comparison makes MySQL and SQLite agree; SQLite already
             * compares TEXT as BINARY and would reject a MySQL collation name.
             */
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $column->collation('utf8mb4_bin');
            }
        });

        $this->backfill();
        $this->guardAgainstCollisions();

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropUnique(['construction_id', 'code']);
            $table->unique(['construction_id', 'code_normalized']);

            /**
             * The installment import resolves a contract by code alone before
             * matching it to a development -- that is what lets it tell "no such
             * contract" apart from "that code belongs to another development".
             * Without this the lookup would fall back to a table scan, because
             * `code_normalized` is not the leftmost column of the unique index.
             */
            $table->index('code_normalized');
        });
    }

    /**
     * Fills the derived column from PHP, never from SQL: `UPPER()` means
     * different things in the two engines, and using it here would bake the very
     * divergence this migration removes into the historical data.
     *
     * Soft deleted contracts are included. Their codes still occupy their
     * development.
     */
    private function backfill(): void
    {
        DB::table('contracts')
            ->select('id', 'code')
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($contracts): void {
                foreach ($contracts as $contract) {
                    DB::table('contracts')
                        ->where('id', $contract->id)
                        ->update(['code_normalized' => Contract::normalizeCodeForComparison($contract->code) ?? '']);
                }
            });
    }

    /**
     * Two contracts of one development whose codes only differed by case or
     * spacing were legal under SQLite and are not legal under the new rule.
     * The survey found none, but "none today" is not "none ever", so the index
     * is only created once this has confirmed it again on the real rows.
     */
    private function guardAgainstCollisions(): void
    {
        $collisions = DB::table('contracts')
            ->select('construction_id', 'code_normalized')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('construction_id', 'code_normalized')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($collisions->isEmpty()) {
            return;
        }

        $details = $collisions->map(function (object $collision): string {
            $codes = DB::table('contracts')
                ->where('construction_id', $collision->construction_id)
                ->where('code_normalized', $collision->code_normalized)
                ->pluck('code', 'id')
                ->map(fn (string $code, int $id): string => "#{$id} \"{$code}\"")
                ->implode(', ');

            return sprintf(
                '  empreendimento %s -> "%s": %s',
                $collision->construction_id,
                $collision->code_normalized,
                $codes,
            );
        })->implode(PHP_EOL);

        throw new RuntimeException(
            'Migration interrompida: existem contratos cujos códigos passam a colidir depois da normalização.'
            .PHP_EOL.'Nenhum dado foi alterado ou removido. Resolva os conflitos abaixo e execute a migration novamente.'
            .PHP_EOL.$details
        );
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropIndex(['code_normalized']);
            $table->dropUnique(['construction_id', 'code_normalized']);
            $table->unique(['construction_id', 'code']);
            $table->dropColumn('code_normalized');
        });
    }
};
