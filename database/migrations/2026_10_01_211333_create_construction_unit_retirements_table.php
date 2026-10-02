<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'construction_unit_retirements';

    private const UNIT_RETIRED_INDEX = 'construction_unit_retirements_unit_retired_index';

    private const OPEN_LOCK_UNIQUE = 'construction_unit_retirements_open_lock_unique';

    private const UNIT_FOREIGN = 'construction_unit_retirements_unit_foreign';

    private const RETIRED_BY_FOREIGN = 'construction_unit_retirements_retired_by_foreign';

    private const REACTIVATED_BY_FOREIGN = 'construction_unit_retirements_reactivated_by_foreign';

    /**
     * Baixa de unidade: a unidade deixa de compor o Quadro de Vendas a partir de
     * uma data, por decisão da Gestão.
     *
     * É fato datado e append-only, como a permuta: uma linha por período de
     * baixa, com vigência semiaberta `[retired_on, reactivated_on)`. A derivação
     * pergunta se a unidade existia no fim de cada competência -- inclusive das
     * passadas ainda abertas --, e um estado na própria unidade ("foto de hoje")
     * responderia com hoje para todas elas. Reativar grava o fim do período, e
     * nada se apaga: baixa, reativação e nova baixa ficam todas consultáveis.
     * Reativar na própria data da baixa a anula -- o período fica vazio.
     *
     * Uma única baixa aberta por unidade, garantida pelo banco: a coluna gerada
     * `open_retirement_lock` vale o id da unidade enquanto a baixa está aberta e
     * nulo depois, e a unique sobre ela recusa a segunda aberta -- o mesmo
     * recurso de `contracts.occupied_unit_lock`, que funciona no MySQL e no
     * SQLite (índice parcial não existe no MySQL).
     *
     * FK RESTRICT na unidade: apagar a unidade apagaria a decisão que a tirou do
     * Quadro. É também a única ação que o MySQL aceita na FK da coluna-base de
     * uma coluna gerada STORED -- CASCADE e SET NULL ali são recusados. A autoria
     * usa SET NULL, como no resto do módulo: a conta pode sumir, a baixa fica.
     *
     * Tabela nova e vazia: nenhuma ALTER em `construction_units` nem em `users`.
     * No MySQL o Laravel cria índices e chaves estrangeiras em comandos separados
     * do CREATE, e o MySQL não desfaz DDL: uma falha entre eles deixaria a tabela
     * pela metade, e a reexecução cairia em "table exists" no startup. Por isso o
     * CREATE é guardado pela existência da tabela e cada índice e cada FK são
     * conferidos depois -- os índices pelo nome, as FKs pelas colunas (no SQLite
     * o nome da FK volta nulo). Os índices vêm antes das FKs: com o índice
     * `(construction_unit_id, retired_on)` já criado, a FK da unidade o usa, e o
     * MySQL não cria um índice implícito só para ela.
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->id();
                $table->foreignId('construction_unit_id');
                $table->date('retired_on');
                $table->text('reason');
                $table->foreignId('retired_by_id')->nullable();
                $table->date('reactivated_on')->nullable();
                $table->timestamp('reactivated_at')->nullable();
                $table->foreignId('reactivated_by_id')->nullable();
                $table->text('reactivation_reason')->nullable();
                $table->unsignedBigInteger('open_retirement_lock')->nullable()
                    ->storedAs('CASE WHEN reactivated_on IS NULL THEN construction_unit_id END');
                $table->timestamps();

                $table->index(['construction_unit_id', 'retired_on'], self::UNIT_RETIRED_INDEX);
                $table->unique('open_retirement_lock', self::OPEN_LOCK_UNIQUE);

                $this->unitForeignKey($table);
                $this->authorForeignKey($table, 'retired_by_id', self::RETIRED_BY_FOREIGN);
                $this->authorForeignKey($table, 'reactivated_by_id', self::REACTIVATED_BY_FOREIGN);
            });
        }

        if (! $this->hasIndex(self::UNIT_RETIRED_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['construction_unit_id', 'retired_on'], self::UNIT_RETIRED_INDEX);
            });
        }

        if (! $this->hasIndex(self::OPEN_LOCK_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique('open_retirement_lock', self::OPEN_LOCK_UNIQUE);
            });
        }

        if (! $this->hasForeignKeyOn('construction_unit_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $this->unitForeignKey($table);
            });
        }

        if (! $this->hasForeignKeyOn('retired_by_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $this->authorForeignKey($table, 'retired_by_id', self::RETIRED_BY_FOREIGN);
            });
        }

        if (! $this->hasForeignKeyOn('reactivated_by_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $this->authorForeignKey($table, 'reactivated_by_id', self::REACTIVATED_BY_FOREIGN);
            });
        }
    }

    /**
     * Só é honesto com a tabela vazia: derrubá-la apaga a trilha das baixas. Em
     * produção a correção é para frente.
     */
    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    private function unitForeignKey(Blueprint $table): void
    {
        $table->foreign('construction_unit_id', self::UNIT_FOREIGN)
            ->references('id')
            ->on('construction_units')
            ->restrictOnDelete();
    }

    private function authorForeignKey(Blueprint $table, string $column, string $name): void
    {
        $table->foreign($column, $name)
            ->references('id')
            ->on('users')
            ->nullOnDelete();
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
