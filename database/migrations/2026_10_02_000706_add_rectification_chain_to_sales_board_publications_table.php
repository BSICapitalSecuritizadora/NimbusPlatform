<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'sales_board_publications';

    private const CYCLE_SEQUENCE_UNIQUE = 'sales_board_publications_cycle_sequence_unique';

    private const BOARD_INDEX = 'sales_board_publications_board_index';

    private const SUPERSEDES_UNIQUE = 'sales_board_publications_supersedes_unique';

    private const SUPERSEDES_FOREIGN = 'sales_board_publications_supersedes_foreign';

    private const RECTIFICATION_FOREIGN = 'sales_board_publications_rectification_foreign';

    private const OLD_CYCLE_UNIQUE = 'sales_board_publications_cycle_unique';

    private const OLD_BOARD_UNIQUE = 'sales_board_publications_board_unique';

    /**
     * A cadeia de publicações: a retificação de uma competência publicada
     * publica de novo o mesmo quadro, e a publicação anterior continua inteira.
     *
     * Até aqui valia "uma publicação por ciclo e um ciclo por quadro" -- as
     * uniques de `sales_board_cycle_id` e de `sales_board_id`. A retificação
     * aprovada atualiza o mesmo `SalesBoard` (o histórico de versões dele grava
     * a mudança) e cria uma publicação nova com `sequence_number` seguinte,
     * apontando a que ela substitui em `supersedes_publication_id` e a
     * retificação que a produziu. As publicações continuam imutáveis: a cadeia
     * é linear porque `(ciclo, sequência)` é única e cada publicação é
     * substituída no máximo uma vez (`supersedes` única).
     *
     * Sem backfill: toda linha existente -- uma por ciclo publicado, garantida
     * pela unique antiga -- recebe `sequence_number = 1` pelo DEFAULT, que no
     * MySQL 8.4 é ADD COLUMN INSTANT, e já é válida na unique nova.
     *
     * A ordem dos passos é obrigatória no MySQL. As uniques antigas sustentam as
     * FKs de `sales_board_cycle_id` e de `sales_board_id`, e o MySQL recusa
     * derrubar o índice de uma FK sem outro que o substitua: por isso a unique
     * `(ciclo, sequência)` e o índice de `sales_board_id` nascem antes dos DROPs.
     * A unique de `supersedes_publication_id` nasce antes da FK dela, para o
     * MySQL não criar o índice implícito da FK, que repetiria a unique. As FKs
     * são ADD CONSTRAINT em tabela pequena (dezenas de linhas): COPY com lock de
     * escrita curto.
     *
     * Cada passo é guardado e roda em Schema::table próprio, porque o MySQL não
     * desfaz DDL: um `migrate --force` interrompido no meio retoma do passo que
     * falta. Índices são conferidos pelo nome e FKs pelas colunas -- no SQLite o
     * nome da FK volta nulo.
     */
    public function up(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'sequence_number')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unsignedSmallInteger('sequence_number')->default(1)->after('sales_board_id');
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'supersedes_publication_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unsignedBigInteger('supersedes_publication_id')->nullable()->after('sequence_number');
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'sales_board_cycle_rectification_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unsignedBigInteger('sales_board_cycle_rectification_id')->nullable()->after('supersedes_publication_id');
            });
        }

        if (! $this->hasIndex(self::CYCLE_SEQUENCE_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(['sales_board_cycle_id', 'sequence_number'], self::CYCLE_SEQUENCE_UNIQUE);
            });
        }

        if (! $this->hasIndex(self::BOARD_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index('sales_board_id', self::BOARD_INDEX);
            });
        }

        if (! $this->hasIndex(self::SUPERSEDES_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique('supersedes_publication_id', self::SUPERSEDES_UNIQUE);
            });
        }

        if (! $this->hasForeignKeyOn('supersedes_publication_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreign('supersedes_publication_id', self::SUPERSEDES_FOREIGN)
                    ->references('id')
                    ->on(self::TABLE)
                    ->restrictOnDelete();
            });
        }

        if (! $this->hasForeignKeyOn('sales_board_cycle_rectification_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreign('sales_board_cycle_rectification_id', self::RECTIFICATION_FOREIGN)
                    ->references('id')
                    ->on('sales_board_cycle_rectifications')
                    ->restrictOnDelete();
            });
        }

        if ($this->hasIndex(self::OLD_CYCLE_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropUnique(self::OLD_CYCLE_UNIQUE);
            });
        }

        if ($this->hasIndex(self::OLD_BOARD_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropUnique(self::OLD_BOARD_UNIQUE);
            });
        }
    }

    /**
     * De mão única depois da primeira retificação publicada: as uniques antigas
     * não cabem num ciclo com duas publicações, e apagar a segunda apagaria a
     * prova de que a posição foi corrigida. Sem cadeia, recria as uniques antigas
     * antes de remover as novas -- as FKs nunca ficam sem índice -- e só então
     * as colunas.
     */
    public function down(): void
    {
        $chained = DB::table(self::TABLE)
            ->select('sales_board_cycle_id')
            ->groupBy('sales_board_cycle_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($chained) {
            throw new RuntimeException('Há competência do Quadro de Vendas com mais de uma publicação (retificação publicada): a cadeia de publicações não é desfeita.');
        }

        if (! $this->hasIndex(self::OLD_CYCLE_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique('sales_board_cycle_id', self::OLD_CYCLE_UNIQUE);
            });
        }

        if (! $this->hasIndex(self::OLD_BOARD_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique('sales_board_id', self::OLD_BOARD_UNIQUE);
            });
        }

        foreach (['sales_board_cycle_rectification_id', 'supersedes_publication_id'] as $column) {
            $foreignKey = $this->foreignKeyOn($column);

            if ($foreignKey !== null) {
                Schema::table(self::TABLE, function (Blueprint $table) use ($foreignKey, $column): void {
                    $table->dropForeign(filled($foreignKey['name'] ?? null) ? $foreignKey['name'] : [$column]);
                });
            }
        }

        foreach ([self::SUPERSEDES_UNIQUE, self::BOARD_INDEX, self::CYCLE_SEQUENCE_UNIQUE] as $index) {
            if (! $this->hasIndex($index)) {
                continue;
            }

            Schema::table(self::TABLE, function (Blueprint $table) use ($index): void {
                $index === self::BOARD_INDEX ? $table->dropIndex($index) : $table->dropUnique($index);
            });
        }

        $columns = array_values(array_filter(
            ['sales_board_cycle_rectification_id', 'supersedes_publication_id', 'sequence_number'],
            fn (string $column): bool => Schema::hasColumn(self::TABLE, $column),
        ));

        if ($columns !== []) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }

    private function hasIndex(string $name): bool
    {
        return collect(Schema::getIndexes(self::TABLE))
            ->contains(fn (array $index): bool => $index['name'] === $name);
    }

    private function hasForeignKeyOn(string $column): bool
    {
        return $this->foreignKeyOn($column) !== null;
    }

    /**
     * @return array{name: string|null, columns: list<string>}|null
     */
    private function foreignKeyOn(string $column): ?array
    {
        return collect(Schema::getForeignKeys(self::TABLE))
            ->first(fn (array $foreignKey): bool => $foreignKey['columns'] === [$column]);
    }
};
