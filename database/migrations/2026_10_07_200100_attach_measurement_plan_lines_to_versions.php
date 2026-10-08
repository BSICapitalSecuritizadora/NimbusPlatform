<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * As linhas do cronograma passam a pertencer a uma versão do plano.
 *
 * Cada linha ganha `plan_version_id` -- a versão de que ela faz parte -- e
 * `lineage_key`, a identidade da medição prevista através das versões: a
 * revisão copia a linha da versão anterior com a mesma chave, e uma linha
 * nova nasce com chave nova. É por ela que a mesma competência não é medida
 * duas vezes só porque a revisão criou outra linha para ela.
 *
 * A sequência passa a ser única por versão, e não mais por plano: a V2 repete
 * as sequências da V1. Uma FK composta (versão, plano) garante que a linha e a
 * versão são do mesmo plano, e a unique (id, versão) é o alvo da FK composta
 * dos arquivos de medição (migration seguinte). A nova unique por versão e o
 * índice (plano, linhagem) nascem antes de a unique antiga sair: no MySQL ela
 * pode ser o índice que sustenta a FK de `plan_set_id`.
 *
 * As linhas existentes -- só de desenvolvimento e homologação -- vão para a V1
 * do plano, cada uma com uma linhagem nova. Idempotente.
 */
return new class extends Migration
{
    private const TABLE = 'measurement_plan_lines';

    private const LEGACY_SEQUENCE_UNIQUE = 'measurement_plan_lines_plan_set_id_sequence_number_unique';

    private const VERSION_SEQUENCE_UNIQUE = 'mpl_version_sequence_unique';

    private const VERSION_LINEAGE_UNIQUE = 'mpl_version_lineage_unique';

    private const ID_VERSION_UNIQUE = 'mpl_id_version_unique';

    private const PLAN_SET_LINEAGE_INDEX = 'mpl_plan_set_lineage_index';

    private const VERSION_FOREIGN = 'mpl_version_plan_set_foreign';

    private const PLAN_OPERATION_FOREIGN = 'mpl_plan_set_operation_foreign';

    private const CHUNK_SIZE = 500;

    public function up(): void
    {
        $this->refuseLinesOfAnotherOperation();

        if (! Schema::hasColumn(self::TABLE, 'plan_version_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unsignedBigInteger('plan_version_id')->nullable()->after('plan_set_id');
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'lineage_key')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->char('lineage_key', 26)->nullable()->after('plan_version_id');
            });
        }

        $this->attachLinesToTheFirstVersion();
        $this->giveEachLineItsLineage();

        $remaining = DB::table(self::TABLE)->whereNull('plan_version_id')->orWhereNull('lineage_key')->count();

        if ($remaining > 0) {
            throw new RuntimeException("measurement_plan_lines ainda tem {$remaining} linha(s) sem versão ou sem linhagem depois do preenchimento; rode a migration de novo.");
        }

        // Uma alteração só para a coluna obrigatória e as FKs compostas: no
        // SQLite cada uma reconstrói a tabela.
        $needsVersionForeign = ! $this->hasForeignKeyOn(['plan_version_id', 'plan_set_id']);
        $needsOperationForeign = ! $this->hasForeignKeyOn(['plan_set_id', 'operation_id']);

        if ($this->isNullable('plan_version_id') || $this->isNullable('lineage_key') || $needsVersionForeign || $needsOperationForeign) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($needsVersionForeign, $needsOperationForeign): void {
                $table->unsignedBigInteger('plan_version_id')->nullable(false)->change();
                $table->char('lineage_key', 26)->nullable(false)->change();

                if ($needsVersionForeign) {
                    $table->foreign(['plan_version_id', 'plan_set_id'], self::VERSION_FOREIGN)
                        ->references(['id', 'plan_set_id'])
                        ->on('measurement_plan_versions')
                        ->cascadeOnDelete();
                }

                if ($needsOperationForeign) {
                    $table->foreign(['plan_set_id', 'operation_id'], self::PLAN_OPERATION_FOREIGN)
                        ->references(['id', 'operation_id'])
                        ->on('measurement_plan_sets')
                        ->cascadeOnDelete();
                }
            });
        }

        foreach ([
            self::PLAN_SET_LINEAGE_INDEX => fn (Blueprint $table) => $table->index(['plan_set_id', 'lineage_key'], self::PLAN_SET_LINEAGE_INDEX),
            self::VERSION_SEQUENCE_UNIQUE => fn (Blueprint $table) => $table->unique(['plan_version_id', 'sequence_number'], self::VERSION_SEQUENCE_UNIQUE),
            self::VERSION_LINEAGE_UNIQUE => fn (Blueprint $table) => $table->unique(['plan_version_id', 'lineage_key'], self::VERSION_LINEAGE_UNIQUE),
            self::ID_VERSION_UNIQUE => fn (Blueprint $table) => $table->unique(['id', 'plan_version_id'], self::ID_VERSION_UNIQUE),
        ] as $name => $definition) {
            if (! $this->hasIndex($name)) {
                Schema::table(self::TABLE, $definition);
            }
        }

        if ($this->hasIndex(self::LEGACY_SEQUENCE_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropUnique(self::LEGACY_SEQUENCE_UNIQUE);
            });
        }

        $this->assertSqliteForeignKeysHold();
    }

    /**
     * A linha carrega a operação do plano (cópia usada pelas consultas da
     * operação). Linha de outra operação é dado a investigar: a FK composta
     * nova a recusaria.
     */
    private function refuseLinesOfAnotherOperation(): void
    {
        $inconsistent = DB::table(self::TABLE)
            ->join('measurement_plan_sets', 'measurement_plan_sets.id', '=', self::TABLE.'.plan_set_id')
            ->whereColumn(self::TABLE.'.operation_id', '<>', 'measurement_plan_sets.operation_id')
            ->orderBy(self::TABLE.'.id')
            ->pluck(self::TABLE.'.id');

        if ($inconsistent->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'measurement_plan_lines possui %d linha(s) cuja operação difere da do plano (ids: %s). Investigue antes de versionar o cronograma.',
                $inconsistent->count(),
                $inconsistent->take(20)->implode(', '),
            ));
        }
    }

    /**
     * No SQLite a reconstrução da tabela roda com as FKs desligadas; aqui se
     * confere que nenhuma referência ficou quebrada.
     */
    private function assertSqliteForeignKeysHold(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        $violations = DB::select('PRAGMA foreign_key_check');

        if ($violations !== []) {
            throw new RuntimeException('A reconstrução de measurement_plan_lines deixou referências quebradas: '.json_encode($violations, JSON_THROW_ON_ERROR));
        }
    }

    /**
     * Volta à sequência única por plano enquanto cada plano tiver só a V1; com
     * revisão gravada a unique antiga não cabe mais, e a reversão é recusada.
     */
    public function down(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'plan_version_id')) {
            return;
        }

        if (DB::table('measurement_plan_versions')->where('version_number', '<>', 1)->exists()) {
            throw new RuntimeException(
                'measurement_plan_lines pertence a revisões do plano; a sequência única por plano não cabe mais. Corrija com uma nova migration.'
            );
        }

        if (! $this->hasIndex(self::LEGACY_SEQUENCE_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(['plan_set_id', 'sequence_number'], self::LEGACY_SEQUENCE_UNIQUE);
            });
        }

        // O SQLite não guarda nome de FK (só se derruba pelas colunas); o MySQL
        // precisa do nome com que ela foi criada.
        Schema::table(self::TABLE, function (Blueprint $table): void {
            foreach ([self::VERSION_FOREIGN => ['plan_version_id', 'plan_set_id'], self::PLAN_OPERATION_FOREIGN => ['plan_set_id', 'operation_id']] as $name => $columns) {
                if ($this->hasForeignKeyOn($columns)) {
                    $table->dropForeign(DB::getDriverName() === 'sqlite' ? $columns : $name);
                }
            }
        });

        foreach ([self::ID_VERSION_UNIQUE, self::VERSION_LINEAGE_UNIQUE, self::VERSION_SEQUENCE_UNIQUE] as $unique) {
            if ($this->hasIndex($unique)) {
                Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropUnique($unique));
            }
        }

        if ($this->hasIndex(self::PLAN_SET_LINEAGE_INDEX)) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropIndex(self::PLAN_SET_LINEAGE_INDEX));
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn(['lineage_key', 'plan_version_id']);
        });
    }

    private function attachLinesToTheFirstVersion(): void
    {
        DB::table(self::TABLE)
            ->whereNull('plan_version_id')
            ->select('id', 'plan_set_id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $lines): void {
                $firstVersions = DB::table('measurement_plan_versions')
                    ->whereIn('plan_set_id', $lines->pluck('plan_set_id')->unique()->all())
                    ->where('version_number', 1)
                    ->pluck('id', 'plan_set_id');

                foreach ($lines as $line) {
                    $versionId = $firstVersions->get($line->plan_set_id);

                    if ($versionId === null) {
                        throw new RuntimeException("A linha #{$line->id} pertence a um plano sem a versão 1; rode antes a migration das versões.");
                    }

                    DB::table(self::TABLE)->where('id', $line->id)->update(['plan_version_id' => $versionId]);
                }
            });
    }

    private function giveEachLineItsLineage(): void
    {
        DB::table(self::TABLE)
            ->whereNull('lineage_key')
            ->select('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $lines): void {
                foreach ($lines as $line) {
                    DB::table(self::TABLE)->where('id', $line->id)->update(['lineage_key' => (string) Str::ulid()]);
                }
            });
    }

    private function isNullable(string $column): bool
    {
        return (bool) (collect(Schema::getColumns(self::TABLE))
            ->firstWhere('name', $column)['nullable'] ?? false);
    }

    private function hasIndex(string $name): bool
    {
        return collect(Schema::getIndexes(self::TABLE))
            ->contains(fn (array $index): bool => $index['name'] === $name);
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasForeignKeyOn(array $columns): bool
    {
        return collect(Schema::getForeignKeys(self::TABLE))
            ->contains(fn (array $foreignKey): bool => $foreignKey['columns'] === $columns);
    }
};
