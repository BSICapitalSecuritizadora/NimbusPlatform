<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'sales_board_cycle_baselines';

    private const COLUMN = 'previous_competence_baseline_id';

    private const FOREIGN_KEY = 'sb_baselines_previous_competence_foreign';

    /**
     * Contra qual versão da competência anterior os movimentos extemporâneos
     * desta versão foram apurados.
     *
     * Metadado de auditoria, fora do fingerprint: a âncora da derivação é a
     * versão publicada vigente da competência anterior (ou a versão vigente
     * dela, se ainda não foi publicada), e quando ela é retificada depois desta
     * versão a "Ponte com a competência anterior" avisa que a anterior mudou.
     * NULL significa "versão congelada antes desta coluna" ou "sem competência
     * anterior" -- não há backfill, porque a âncora de uma versão passada não
     * se reconstrói com a fonte de hoje.
     *
     * FK RESTRICT para a própria tabela: versões nunca são apagadas, e a que
     * serviu de âncora continua alcançável. ADD COLUMN nula é INSTANT no MySQL
     * 8.4; a FK faz COPY da tabela de versões (uma linha por versão de ciclo),
     * com lock de escrita curto. Guardada por etapa: o MySQL não desfaz DDL, e
     * um retorno cedo pela coluna pularia a FK para sempre se ela falhasse. A
     * FK é procurada pelas colunas -- no SQLite o nome dela volta nulo.
     */
    public function up(): void
    {
        if (! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unsignedBigInteger(self::COLUMN)->nullable()->after('warnings');
            });
        }

        if ($this->foreignKey() === null) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreign(self::COLUMN, self::FOREIGN_KEY)
                    ->references('id')
                    ->on(self::TABLE)
                    ->restrictOnDelete();
            });
        }
    }

    /**
     * A FK é removida pelo nome que o banco devolve; no SQLite, que não guarda
     * nome de FK, pela coluna -- a forma que ele aceita.
     */
    public function down(): void
    {
        $foreignKey = $this->foreignKey();

        if ($foreignKey !== null) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($foreignKey): void {
                $table->dropForeign(filled($foreignKey['name'] ?? null) ? $foreignKey['name'] : [self::COLUMN]);
            });
        }

        if (Schema::hasColumn(self::TABLE, self::COLUMN)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropColumn(self::COLUMN);
            });
        }
    }

    /**
     * @return array{name: string|null, columns: list<string>}|null
     */
    private function foreignKey(): ?array
    {
        return collect(Schema::getForeignKeys(self::TABLE))
            ->first(fn (array $foreignKey): bool => $foreignKey['columns'] === [self::COLUMN]);
    }
};
