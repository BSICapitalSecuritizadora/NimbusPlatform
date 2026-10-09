<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Revisões de medição (R0, R1, R2...): identidade da medição lógica.
 *
 * Cada revisão é uma linha de `measurements` -- com fluxo, análises, snapshot da
 * Engenharia, arquivos e pagamentos próprios -- e a família agrupa as revisões
 * de uma mesma medição lógica:
 *
 * - `revision_family_id`: o id da R0 da família. Na R0 é o próprio id, gravado
 *   logo depois da inserção (o MySQL não aceita coluna gerada, default nem CHECK
 *   sobre a coluna AUTO_INCREMENT); por isso não tem FK -- uma FK de uma linha
 *   para ela mesma impediria excluir a medição que nunca entrou no fluxo
 *   (o InnoDB recusa apagar a linha que se referencia, com RESTRICT).
 * - `revision_root_id`: a R0, só nas revisões (FK RESTRICT): a R0 com revisões
 *   não se exclui, e a raiz é da mesma operação (FK composta).
 * - `previous_revision_id` + `previous_revision_number`: a revisão que esta
 *   substitui, da mesma família e com número menor (FK composta + CHECK) -- sem
 *   ciclo possível nos números.
 * - `revision_status`: rascunho, em análise, vigente, substituída, recusada,
 *   cancelada. No máximo uma vigente e uma pendente por família, garantidas por
 *   colunas geradas VIRTUAL com unique (o padrão das versões do plano).
 *
 * - `previous_snapshot_sha256`: o hash canônico do registro da Engenharia da
 *   revisão anterior quando a revisão foi criada; o envio e a vigência recusam
 *   o rascunho feito sobre um registro que depois mudou.
 *
 * As medições existentes são todas R0 vigentes: família = o próprio id, número
 * 0. Nada é inventado -- nem revisão, nem data de vigência.
 *
 * Idempotente, como as migrations da Fase 2: no MySQL o DDL não se desfaz, e
 * uma falha no meio não pode deixar a reexecução sem saída -- cada coluna é
 * conferida uma a uma. Nenhuma instrução de schema roda dentro de transação (no
 * SQLite, a reconstrução de tabela dentro de uma transação apagaria em cascata
 * os filhos de `measurements`).
 *
 * O MySQL cria, para cada FK sem índice que a sirva, um índice com o nome da
 * própria FK, e o `DROP FOREIGN KEY` o deixa para trás -- com as colunas que
 * sobrarem, se só parte delas for removida; aí ele pode passar a sustentar
 * outra FK e não sai mais. A FK composta do arquivo herdado ganha um índice
 * explícito, criado antes dela: o MySQL não cria o implícito, e um índice com
 * o nome da FK deixado por uma reversão anterior não colide ("Duplicate key
 * name"). O `down()` remove, junto com cada FK, o índice dela que ainda comece
 * pela coluna da própria FK -- nunca um que já sustente outra.
 */
return new class extends Migration
{
    private const TABLE = 'measurements';

    private const NUMBER_UNIQUE = 'm_rev_number_unique';

    private const ID_OPERATION_UNIQUE = 'm_rev_id_operation_unique';

    private const ID_FAMILY_NUMBER_UNIQUE = 'm_rev_id_family_number_unique';

    private const EFFECTIVE_UNIQUE = 'm_rev_effective_unique';

    private const PENDING_UNIQUE = 'm_rev_pending_unique';

    private const STATUS_CHECK = 'm_rev_status_check';

    private const IDENTITY_CHECK = 'm_rev_identity_check';

    private const WORKFLOW_CHECK = 'm_rev_workflow_check';

    private const ASSET_INHERITED_UNIQUE = 'ma_inherited_unique';

    private const ASSET_CONTEXT_UNIQUE = 'ma_id_context_unique';

    private const ASSET_INHERITED_FOREIGN = 'ma_inherited_context_foreign';

    private const ASSET_INHERITED_INDEX = 'ma_inherited_context_index';

    /**
     * @var array<string, array{columns: list<string>, on: string, references: list<string>, onDelete: string}>
     */
    private const FOREIGN_KEYS = [
        'm_rev_root_foreign' => ['columns' => ['revision_root_id'], 'on' => 'measurements', 'references' => ['id'], 'onDelete' => 'restrict'],
        'm_rev_previous_foreign' => ['columns' => ['previous_revision_id'], 'on' => 'measurements', 'references' => ['id'], 'onDelete' => 'restrict'],
        'm_rev_root_operation_foreign' => ['columns' => ['revision_root_id', 'operation_id'], 'on' => 'measurements', 'references' => ['id', 'operation_id'], 'onDelete' => 'restrict'],
        'm_rev_previous_chain_foreign' => ['columns' => ['previous_revision_id', 'revision_family_id', 'previous_revision_number'], 'on' => 'measurements', 'references' => ['id', 'revision_family_id', 'revision_number'], 'onDelete' => 'restrict'],
        'm_rev_created_by_foreign' => ['columns' => ['revision_created_by'], 'on' => 'users', 'references' => ['id'], 'onDelete' => 'set null'],
        'm_rev_closed_by_foreign' => ['columns' => ['revision_closed_by'], 'on' => 'users', 'references' => ['id'], 'onDelete' => 'set null'],
    ];

    public function up(): void
    {
        $this->addRevisionColumns();
        $this->backfillOriginals();
        $this->addParentKeys();
        $this->addForeignKeys();
        $this->addGeneratedUniques();
        $this->addMysqlChecks();
        $this->addAssetInheritance();
        $this->assertSqliteForeignKeysHold();
    }

    /**
     * Desfaz só enquanto não há revisão: depois da primeira R1, voltar apagaria
     * a identidade da família e deixaria pagamentos e snapshots sem a revisão a
     * que pertencem.
     */
    public function down(): void
    {
        if (Schema::hasColumn(self::TABLE, 'revision_number')
            && DB::table(self::TABLE)
                ->where('revision_number', '>', 0)
                ->orWhere('revision_status', '<>', 'effective')
                ->exists()) {
            throw new RuntimeException('Existem revisões de medição registradas: a identidade das revisões não pode ser removida sem perder o histórico.');
        }

        if (Schema::hasColumn('measurement_assets', 'inherited_from_asset_id')) {
            Schema::table('measurement_assets', function (Blueprint $table): void {
                if ($this->hasForeignKeyOn('measurement_assets', ['inherited_from_asset_id', 'plan_set_id', 'plan_version_id', 'plan_line_id'])) {
                    $table->dropForeign(DB::getDriverName() === 'sqlite'
                        ? ['inherited_from_asset_id', 'plan_set_id', 'plan_version_id', 'plan_line_id']
                        : self::ASSET_INHERITED_FOREIGN);
                }
            });

            Schema::table('measurement_assets', function (Blueprint $table): void {
                if ($this->hasIndex('measurement_assets', self::ASSET_INHERITED_INDEX)) {
                    $table->dropIndex(self::ASSET_INHERITED_INDEX);
                }
            });

            // O índice implícito de um banco migrado antes do índice explícito.
            $this->dropLeftoverForeignKeyIndexes('measurement_assets', [self::ASSET_INHERITED_FOREIGN => 'inherited_from_asset_id']);

            Schema::table('measurement_assets', function (Blueprint $table): void {
                foreach ([self::ASSET_INHERITED_UNIQUE, self::ASSET_CONTEXT_UNIQUE] as $index) {
                    if ($this->hasIndex('measurement_assets', $index)) {
                        $table->dropUnique($index);
                    }
                }
            });

            Schema::table('measurement_assets', fn (Blueprint $table) => $table->dropColumn('inherited_from_asset_id'));
        }

        if (DB::getDriverName() === 'mysql') {
            foreach ([self::STATUS_CHECK, self::IDENTITY_CHECK, self::WORKFLOW_CHECK] as $check) {
                if ($this->hasCheck($check)) {
                    DB::statement(sprintf('ALTER TABLE %s DROP CHECK %s', self::TABLE, $check));
                }
            }
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            foreach (self::FOREIGN_KEYS as $name => $foreignKey) {
                if ($this->hasForeignKeyOn(self::TABLE, $foreignKey['columns'])) {
                    $table->dropForeign(DB::getDriverName() === 'sqlite' ? $foreignKey['columns'] : $name);
                }
            }
        });

        $this->dropLeftoverForeignKeyIndexes(self::TABLE, $this->foreignKeyLeadingColumns(self::FOREIGN_KEYS));

        Schema::table(self::TABLE, function (Blueprint $table): void {
            foreach ([self::EFFECTIVE_UNIQUE, self::PENDING_UNIQUE, self::NUMBER_UNIQUE, self::ID_FAMILY_NUMBER_UNIQUE, self::ID_OPERATION_UNIQUE] as $index) {
                if ($this->hasIndex(self::TABLE, $index)) {
                    $table->dropUnique($index);
                }
            }
        });

        foreach (['effective_revision_family_id', 'pending_revision_family_id'] as $generated) {
            if (Schema::hasColumn(self::TABLE, $generated)) {
                Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropColumn($generated));
            }
        }

        $columns = array_values(array_filter(
            array_keys($this->revisionColumns()),
            fn (string $column): bool => Schema::hasColumn(self::TABLE, $column),
        ));

        if ($columns !== []) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }

    /**
     * Cada coluna conferida uma a uma: no MySQL cada uma é um ALTER próprio, e a
     * reexecução depois de uma interrupção acrescenta só o que faltou.
     */
    private function addRevisionColumns(): void
    {
        foreach ($this->revisionColumns() as $column => $add) {
            if (! Schema::hasColumn(self::TABLE, $column)) {
                Schema::table(self::TABLE, fn (Blueprint $table) => $add($table));
            }
        }
    }

    /**
     * As colunas da identidade da revisão, na ordem em que entram.
     *
     * @return array<string, Closure(Blueprint): mixed>
     */
    private function revisionColumns(): array
    {
        return [
            'revision_family_id' => fn (Blueprint $table) => $table->unsignedBigInteger('revision_family_id')->nullable()->after('operation_id'),
            'revision_root_id' => fn (Blueprint $table) => $table->unsignedBigInteger('revision_root_id')->nullable()->after('revision_family_id'),
            'revision_number' => fn (Blueprint $table) => $table->unsignedSmallInteger('revision_number')->default(0)->after('revision_root_id'),
            'previous_revision_id' => fn (Blueprint $table) => $table->unsignedBigInteger('previous_revision_id')->nullable()->after('revision_number'),
            'previous_revision_number' => fn (Blueprint $table) => $table->unsignedSmallInteger('previous_revision_number')->nullable()->after('previous_revision_id'),
            // Binária e NO PAD, como a situação das versões do plano: na collation
            // padrão do MySQL 'Effective' ocuparia a vaga de vigente e passaria no
            // CHECK, e o SQLite (que compara byte a byte) não.
            'revision_status' => function (Blueprint $table): void {
                $status = $table->string('revision_status', 20)->default('effective')->after('previous_revision_number');

                if (DB::getDriverName() === 'mysql') {
                    $status->collation('utf8mb4_0900_bin');
                }
            },
            'revision_reason' => fn (Blueprint $table) => $table->text('revision_reason')->nullable()->after('revision_status'),
            'revision_created_by' => fn (Blueprint $table) => $table->unsignedBigInteger('revision_created_by')->nullable()->after('revision_reason'),
            'revision_effective_at' => fn (Blueprint $table) => $table->timestamp('revision_effective_at')->nullable()->after('revision_created_by'),
            'revision_superseded_at' => fn (Blueprint $table) => $table->timestamp('revision_superseded_at')->nullable()->after('revision_effective_at'),
            'revision_closed_at' => fn (Blueprint $table) => $table->timestamp('revision_closed_at')->nullable()->after('revision_superseded_at'),
            'revision_closed_by' => fn (Blueprint $table) => $table->unsignedBigInteger('revision_closed_by')->nullable()->after('revision_closed_at'),
            'revision_closed_reason' => fn (Blueprint $table) => $table->text('revision_closed_reason')->nullable()->after('revision_closed_by'),
            'previous_snapshot_sha256' => fn (Blueprint $table) => $table->char('previous_snapshot_sha256', 64)->nullable()->after('revision_closed_reason'),
        ];
    }

    /**
     * Toda medição existente é a R0 da própria família. Uma instrução só: a
     * família de cada linha é o próprio id, sem depender de outra linha, e a
     * reexecução não acha mais nada a fazer.
     */
    private function backfillOriginals(): void
    {
        DB::table(self::TABLE)
            ->whereNull('revision_family_id')
            ->where('revision_number', 0)
            ->update(['revision_family_id' => DB::raw('id')]);
    }

    /**
     * Alvos das FKs compostas -- criados antes delas (o MySQL recusa a FK sem a
     * unique do pai; o SQLite acusa "foreign key mismatch").
     */
    private function addParentKeys(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (! $this->hasIndex(self::TABLE, self::ID_OPERATION_UNIQUE)) {
                $table->unique(['id', 'operation_id'], self::ID_OPERATION_UNIQUE);
            }

            if (! $this->hasIndex(self::TABLE, self::ID_FAMILY_NUMBER_UNIQUE)) {
                $table->unique(['id', 'revision_family_id', 'revision_number'], self::ID_FAMILY_NUMBER_UNIQUE);
            }

            if (! $this->hasIndex(self::TABLE, self::NUMBER_UNIQUE)) {
                $table->unique(['revision_family_id', 'revision_number'], self::NUMBER_UNIQUE);
            }
        });
    }

    /**
     * Uma alteração de tabela só, para o SQLite reconstruí-la uma vez.
     */
    private function addForeignKeys(): void
    {
        $missing = collect(self::FOREIGN_KEYS)
            ->reject(fn (array $foreignKey): bool => $this->hasForeignKeyOn(self::TABLE, $foreignKey['columns']));

        if ($missing->isEmpty()) {
            return;
        }

        $this->dropLeftoverForeignKeyIndexes(self::TABLE, $this->foreignKeyLeadingColumns($missing->all()));

        Schema::table(self::TABLE, function (Blueprint $table) use ($missing): void {
            foreach ($missing as $name => $foreignKey) {
                $definition = $table->foreign($foreignKey['columns'], $name)
                    ->references($foreignKey['references'])
                    ->on($foreignKey['on']);

                match ($foreignKey['onDelete']) {
                    'set null' => $definition->nullOnDelete(),
                    default => $definition->restrictOnDelete(),
                };
            }
        });
    }

    /**
     * No máximo uma revisão vigente e uma pendente (rascunho ou em análise) por
     * família. VIRTUAL, como `active_plan_set_id` das versões: o SQLite não
     * acrescenta coluna STORED a tabela existente.
     */
    private function addGeneratedUniques(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'effective_revision_family_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unsignedBigInteger('effective_revision_family_id')->nullable()
                    ->virtualAs("CASE WHEN revision_status = 'effective' THEN revision_family_id END");
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'pending_revision_family_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unsignedBigInteger('pending_revision_family_id')->nullable()
                    ->virtualAs("CASE WHEN revision_status IN ('draft', 'under_review') THEN revision_family_id END");
            });
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (! $this->hasIndex(self::TABLE, self::EFFECTIVE_UNIQUE)) {
                $table->unique('effective_revision_family_id', self::EFFECTIVE_UNIQUE);
            }

            if (! $this->hasIndex(self::TABLE, self::PENDING_UNIQUE)) {
                $table->unique('pending_revision_family_id', self::PENDING_UNIQUE);
            }
        });
    }

    /**
     * No MySQL o banco também recusa situação desconhecida, número sem a
     * revisão anterior (ou com número maior que o seu), revisão sem raiz, sem
     * motivo, e revisão pendente com o fluxo encerrado. O SQLite dos testes não
     * aceita ADD CONSTRAINT; lá a regra fica no domínio. Nenhum CHECK usa coluna
     * de FK com SET NULL (o MySQL recusaria).
     */
    private function addMysqlChecks(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $checks = [
            self::STATUS_CHECK => "revision_status IN ('draft', 'under_review', 'effective', 'superseded', 'rejected', 'cancelled')",
            self::IDENTITY_CHECK => '(revision_number = 0 AND revision_root_id IS NULL AND previous_revision_id IS NULL AND previous_revision_number IS NULL)'
                .' OR (revision_number > 0 AND revision_root_id IS NOT NULL AND revision_family_id = revision_root_id'
                .' AND previous_revision_id IS NOT NULL AND previous_revision_number IS NOT NULL AND previous_revision_number < revision_number'
                .' AND revision_reason IS NOT NULL AND CHAR_LENGTH(TRIM(revision_reason)) > 0)',
            self::WORKFLOW_CHECK => "(revision_status NOT IN ('draft', 'under_review') OR status IN ('pending', 'in_review', 'paused'))"
                ." AND ((status = 'cancelled') = (revision_status = 'cancelled'))"
                ." AND (status <> 'superseded' OR revision_status = 'superseded')",
        ];

        foreach ($checks as $name => $expression) {
            if (! $this->hasCheck($name)) {
                DB::statement(sprintf('ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s)', self::TABLE, $name, $expression));
            }
        }
    }

    /**
     * O arquivo da revisão herda o contexto do plano (plano, versão e linha) do
     * arquivo da revisão anterior: a FK composta garante no banco que a herança
     * é do mesmo contexto -- e, pelo plano, da mesma operação. Um arquivo herdado
     * só uma vez por revisão.
     */
    private function addAssetInheritance(): void
    {
        if (! Schema::hasColumn('measurement_assets', 'inherited_from_asset_id')) {
            Schema::table('measurement_assets', function (Blueprint $table): void {
                $table->unsignedBigInteger('inherited_from_asset_id')->nullable()->after('measurement_id');
            });
        }

        Schema::table('measurement_assets', function (Blueprint $table): void {
            if (! $this->hasIndex('measurement_assets', self::ASSET_CONTEXT_UNIQUE)) {
                $table->unique(['id', 'plan_set_id', 'plan_version_id', 'plan_line_id'], self::ASSET_CONTEXT_UNIQUE);
            }

            if (! $this->hasIndex('measurement_assets', self::ASSET_INHERITED_UNIQUE)) {
                $table->unique(['measurement_id', 'inherited_from_asset_id'], self::ASSET_INHERITED_UNIQUE);
            }
        });

        $columns = ['inherited_from_asset_id', 'plan_set_id', 'plan_version_id', 'plan_line_id'];

        if (! $this->hasIndex('measurement_assets', self::ASSET_INHERITED_INDEX)) {
            Schema::table('measurement_assets', fn (Blueprint $table) => $table->index($columns, self::ASSET_INHERITED_INDEX));
        }

        if (! $this->hasForeignKeyOn('measurement_assets', $columns)) {
            Schema::table('measurement_assets', function (Blueprint $table) use ($columns): void {
                $table->foreign($columns, self::ASSET_INHERITED_FOREIGN)
                    ->references(['id', 'plan_set_id', 'plan_version_id', 'plan_line_id'])
                    ->on('measurement_assets')
                    ->restrictOnDelete();
            });
        }
    }

    /**
     * No SQLite a reconstrução das tabelas roda com as FKs desligadas; aqui se
     * confere que nenhuma referência ficou quebrada.
     */
    private function assertSqliteForeignKeysHold(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        $violations = DB::select('PRAGMA foreign_key_check');

        if ($violations !== []) {
            throw new RuntimeException('A reconstrução das tabelas de medição deixou referências quebradas: '.json_encode($violations, JSON_THROW_ON_ERROR));
        }
    }

    /**
     * No MySQL, o índice que a FK criou com o próprio nome e que ficou depois de
     * a FK sair -- só enquanto ainda começa pela primeira coluna da própria FK:
     * nesse caso nenhuma outra FK o usa. O que perdeu essa coluna pode
     * sustentar outra FK e fica (o MySQL recusaria removê-lo).
     *
     * @param  array<string, string>  $foreignKeys  nome da FK => a primeira coluna dela
     */
    private function dropLeftoverForeignKeyIndexes(string $table, array $foreignKeys): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $leftovers = collect(Schema::getIndexes($table))
            ->filter(fn (array $index): bool => array_key_exists($index['name'], $foreignKeys)
                && ($index['columns'][0] ?? null) === $foreignKeys[$index['name']])
            ->pluck('name')
            ->values()
            ->all();

        if ($leftovers === []) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($leftovers): void {
            foreach ($leftovers as $index) {
                $blueprint->dropIndex($index);
            }
        });
    }

    /**
     * @param  array<string, array{columns: list<string>, on: string, references: list<string>, onDelete: string}>  $foreignKeys
     * @return array<string, string>
     */
    private function foreignKeyLeadingColumns(array $foreignKeys): array
    {
        return array_map(fn (array $foreignKey): string => $foreignKey['columns'][0], $foreignKeys);
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => $index['name'] === $name);
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasForeignKeyOn(string $table, array $columns): bool
    {
        return collect(Schema::getForeignKeys($table))
            ->contains(fn (array $foreignKey): bool => $foreignKey['columns'] === $columns);
    }

    private function hasCheck(string $name): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', self::TABLE)
            ->where('CONSTRAINT_NAME', $name)
            ->exists();
    }
};
