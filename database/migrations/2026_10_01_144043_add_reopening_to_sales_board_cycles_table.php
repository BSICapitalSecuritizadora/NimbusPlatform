<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'sales_board_cycles';

    private const FOREIGN_KEY = 'sales_board_cycles_reopened_by_foreign';

    /**
     * Quem reabriu a competência cancelada, quando e por quê.
     *
     * "Reabrir competência" devolve o mesmo ciclo cancelado a "Gerado". As
     * colunas de cancelamento continuam como registro do último cancelamento, e
     * estas registram a volta. Anuláveis e sem backfill: nulo significa "nunca
     * reaberta", que é a situação de toda linha existente.
     *
     * Guardada por etapa, porque DDL no MySQL não é transacional: se a coluna
     * entrar e a constraint falhar, a reexecução precisa criar só o que falta.
     * Um retorno cedo por "a coluna já existe" pularia a FK para sempre. As
     * colunas são conferidas uma a uma e a FK pelas colunas que ela cobre -- no
     * SQLite o nome da FK volta nulo.
     */
    public function up(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'reopened_at')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->timestamp('reopened_at')->nullable()->after('cancellation_reason');
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'reopened_by_user_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unsignedBigInteger('reopened_by_user_id')->nullable()->after('reopened_at');
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'reopen_reason')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->text('reopen_reason')->nullable()->after('reopened_by_user_id');
            });
        }

        if ($this->reopenedByForeignKey() === null) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreign('reopened_by_user_id', self::FOREIGN_KEY)
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            });
        }
    }

    /**
     * A FK é removida pelo nome que o banco devolve; no SQLite, que não guarda
     * nome de FK, pela coluna -- a forma que ele aceita.
     */
    public function down(): void
    {
        $foreignKey = $this->reopenedByForeignKey();

        if ($foreignKey !== null) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($foreignKey): void {
                $table->dropForeign(filled($foreignKey['name'] ?? null) ? $foreignKey['name'] : ['reopened_by_user_id']);
            });
        }

        $columns = array_values(array_filter(
            ['reopened_at', 'reopened_by_user_id', 'reopen_reason'],
            fn (string $column): bool => Schema::hasColumn(self::TABLE, $column),
        ));

        if ($columns !== []) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }

    /**
     * @return array{name: string|null, columns: list<string>}|null
     */
    private function reopenedByForeignKey(): ?array
    {
        return collect(Schema::getForeignKeys(self::TABLE))
            ->first(fn (array $foreignKey): bool => $foreignKey['columns'] === ['reopened_by_user_id']);
    }
};
