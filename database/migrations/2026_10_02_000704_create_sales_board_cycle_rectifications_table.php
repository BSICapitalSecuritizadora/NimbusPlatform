<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'sales_board_cycle_rectifications';

    private const CYCLE_SEQUENCE_UNIQUE = 'sb_rectifications_cycle_sequence_unique';

    private const OPEN_CYCLE_UNIQUE = 'sb_rectifications_open_cycle_unique';

    /**
     * @var array<string, array{column: string, on: string, name: string, nullOnDelete: bool}>
     */
    private const FOREIGN_KEYS = [
        'sales_board_cycle_id' => ['column' => 'sales_board_cycle_id', 'on' => 'sales_board_cycles', 'name' => 'sb_rectifications_cycle_foreign', 'nullOnDelete' => false],
        'rectified_publication_id' => ['column' => 'rectified_publication_id', 'on' => 'sales_board_publications', 'name' => 'sb_rectifications_rectified_publication_foreign', 'nullOnDelete' => false],
        'opening_baseline_id' => ['column' => 'opening_baseline_id', 'on' => 'sales_board_cycle_baselines', 'name' => 'sb_rectifications_opening_baseline_foreign', 'nullOnDelete' => false],
        'requested_by_user_id' => ['column' => 'requested_by_user_id', 'on' => 'users', 'name' => 'sb_rectifications_requested_by_foreign', 'nullOnDelete' => true],
        'closed_by_user_id' => ['column' => 'closed_by_user_id', 'on' => 'users', 'name' => 'sb_rectifications_closed_by_foreign', 'nullOnDelete' => true],
    ];

    /**
     * A retificação de uma competência já publicada do Quadro de Vendas.
     *
     * Uma linha por pedido, com o ciclo de vida "aberta" → "publicada" ou
     * "desistida". "Em retificação" não é status novo do ciclo: é a existência
     * da linha aberta. O ciclo volta a "Gerado" e percorre validação, análise e
     * aprovação como sempre; a publicação que sai da aprovação aponta para esta
     * linha, e a publicação que ela substitui fica guardada em
     * `rectified_publication_id`. Nada se apaga: quem pediu, quando, por quê,
     * quem fechou e com que desfecho ficam na linha e na trilha `sales_board`.
     *
     * No máximo uma aberta por ciclo, garantida pelo banco: a coluna gerada
     * `open_cycle_lock` vale o id do ciclo enquanto a retificação está aberta e
     * nulo depois, e a unique sobre ela recusa a segunda aberta -- o mesmo
     * recurso de `contracts.occupied_unit_lock`, que funciona no MySQL e no
     * SQLite (índice parcial não existe no MySQL). A FK do ciclo é RESTRICT, a
     * única ação que o MySQL aceita na coluna-base de uma coluna gerada STORED.
     *
     * Tabela nova e vazia: nenhuma ALTER em tabela existente. No MySQL o Laravel
     * cria índices e chaves estrangeiras em comandos separados do CREATE, e o
     * MySQL não desfaz DDL: uma falha entre eles deixaria a tabela pela metade,
     * e a reexecução cairia em "table exists" no startup. Por isso o CREATE é
     * guardado pela existência da tabela e cada índice e cada FK são conferidos
     * depois -- os índices pelo nome, as FKs pelas colunas (no SQLite o nome da
     * FK volta nulo). A unique `(ciclo, sequência)` vem antes das FKs: com ela
     * criada, a FK do ciclo a usa, e o MySQL não cria um índice implícito só
     * para ela.
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('sales_board_cycle_id');
                $table->unsignedSmallInteger('sequence_number');
                $table->string('status', 20);
                $table->unsignedBigInteger('rectified_publication_id');
                $table->unsignedBigInteger('opening_baseline_id');
                $table->text('reason');
                $table->unsignedBigInteger('requested_by_user_id')->nullable();
                $table->timestamp('requested_at');
                $table->timestamp('closed_at')->nullable();
                $table->unsignedBigInteger('closed_by_user_id')->nullable();
                $table->text('closing_reason')->nullable();
                $table->unsignedBigInteger('open_cycle_lock')->nullable()
                    ->storedAs("CASE WHEN status = 'aberta' THEN sales_board_cycle_id END");
                $table->timestamps();

                $table->unique(['sales_board_cycle_id', 'sequence_number'], self::CYCLE_SEQUENCE_UNIQUE);
                $table->unique('open_cycle_lock', self::OPEN_CYCLE_UNIQUE);

                foreach (self::FOREIGN_KEYS as $foreignKey) {
                    $this->foreignKey($table, $foreignKey);
                }
            });
        }

        if (! $this->hasIndex(self::CYCLE_SEQUENCE_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(['sales_board_cycle_id', 'sequence_number'], self::CYCLE_SEQUENCE_UNIQUE);
            });
        }

        if (! $this->hasIndex(self::OPEN_CYCLE_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique('open_cycle_lock', self::OPEN_CYCLE_UNIQUE);
            });
        }

        foreach (self::FOREIGN_KEYS as $foreignKey) {
            if ($this->hasForeignKeyOn($foreignKey['column'])) {
                continue;
            }

            Schema::table(self::TABLE, function (Blueprint $table) use ($foreignKey): void {
                $this->foreignKey($table, $foreignKey);
            });
        }
    }

    /**
     * Só é honesto com a tabela vazia: derrubá-la apagaria quem pediu cada
     * retificação e por quê. Com linha gravada, a correção é para frente.
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (DB::table(self::TABLE)->exists()) {
            throw new RuntimeException('sales_board_cycle_rectifications já tem retificações registradas: a migration não é desfeita, a correção é para frente.');
        }

        Schema::dropIfExists(self::TABLE);
    }

    /**
     * @param  array{column: string, on: string, name: string, nullOnDelete: bool}  $foreignKey
     */
    private function foreignKey(Blueprint $table, array $foreignKey): void
    {
        $definition = $table->foreign($foreignKey['column'], $foreignKey['name'])
            ->references('id')
            ->on($foreignKey['on']);

        $foreignKey['nullOnDelete'] ? $definition->nullOnDelete() : $definition->restrictOnDelete();
    }

    private function hasIndex(string $name): bool
    {
        return collect(Schema::getIndexes(self::TABLE))
            ->contains(fn (array $index): bool => $index['name'] === $name);
    }

    private function hasForeignKeyOn(string $column): bool
    {
        return collect(Schema::getForeignKeys(self::TABLE))
            ->contains(fn (array $foreignKey): bool => $foreignKey['columns'] === [$column]);
    }
};
