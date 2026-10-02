<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Duas coisas que a competência de garantias passa a guardar na própria linha.
 *
 * - `outstanding_balance_outdated_*`: quando e por que o saldo devedor que a
 *   competência apurou deixou de ser o que a fonte de PU responde hoje (curva
 *   homologada ou invalidada, Histórico de PU importado, verificação diária). É
 *   a segunda marca de desatualização, ao lado de `sales_board_outdated_at`, e
 *   tem motivo próprio porque a origem não é o Quadro de Vendas;
 * - `reopened_*`: a última reabertura -- quando, quem e por quê. O motivo vivia
 *   só no activity_log; na linha ele sobrevive a qualquer política de retenção,
 *   e continua preenchido depois de um novo fechamento.
 *
 * Todas nulas e sem default: linha antiga fica "sem marca" e "nunca reaberta",
 * que é a leitura correta. Não há backfill de reabertura antiga -- o evento
 * continua no activity_log, agora protegido.
 *
 * Cada coluna tem a sua guarda, e a FK a dela. No MySQL o Laravel emite um
 * ALTER por coluna -- duas colunas declaradas no mesmo `Schema::table()` não são
 * um comando atômico -- e o MySQL não desfaz DDL: uma interrupção entre dois
 * ALTERs deixa parte das colunas criada. Guardar um grupo pela primeira coluna
 * faria a reexecução do `migrate --force` do startup pular as que faltaram (e a
 * apuração cairia com "Unknown column") ou tentar a FK sem a coluna dela (e
 * todo startup cairia no mesmo erro). Conferindo coluna a coluna, a reexecução
 * cria só o que falta. Um reinício que encontre o ALTER anterior ainda
 * esperando o lock de metadados pode cair uma vez com coluna duplicada; o
 * startup seguinte encontra a coluna e segue. A FK é procurada pelas colunas, e
 * não pelo nome, porque o SQLite dos testes devolve o nome nulo.
 */
return new class extends Migration
{
    private const TABLE = 'guarantee_snapshots';

    private const FOREIGN_KEY = 'guarantee_snapshots_reopened_by_fk';

    public function up(): void
    {
        foreach ($this->columns() as $column => $define) {
            if (Schema::hasColumn(self::TABLE, $column)) {
                continue;
            }

            Schema::table(self::TABLE, function (Blueprint $table) use ($define): void {
                $define($table);
            });
        }

        if ($this->reopenedByForeignKey() === null) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreign('reopened_by', self::FOREIGN_KEY)
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            });
        }
    }

    /**
     * Guardado como o `up()`: remove a FK e só as colunas que existirem, de
     * modo que um rollback depois de uma falha no meio também termina. A FK é
     * removida pelo nome que o banco devolve; no SQLite, que não guarda nome de
     * FK, pela coluna -- a forma que ele aceita.
     */
    public function down(): void
    {
        $foreignKey = $this->reopenedByForeignKey();

        if ($foreignKey !== null) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($foreignKey): void {
                $table->dropForeign(filled($foreignKey['name'] ?? null) ? $foreignKey['name'] : ['reopened_by']);
            });
        }

        $columns = array_values(array_filter(
            array_keys($this->columns()),
            fn (string $column): bool => Schema::hasColumn(self::TABLE, $column),
        ));

        if ($columns !== []) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }

    /**
     * As colunas na ordem dos `after()`: cada uma depende da anterior já
     * existir para ficar no lugar certo.
     *
     * @return array<string, Closure(Blueprint): mixed>
     */
    private function columns(): array
    {
        return [
            'outstanding_balance_outdated_at' => fn (Blueprint $table) => $table->timestamp('outstanding_balance_outdated_at')->nullable()->after('sales_board_outdated_at'),
            'outstanding_balance_outdated_reason' => fn (Blueprint $table) => $table->string('outstanding_balance_outdated_reason', 255)->nullable()->after('outstanding_balance_outdated_at'),
            'reopened_at' => fn (Blueprint $table) => $table->timestamp('reopened_at')->nullable()->after('partial_coverage_confirmed_by'),
            'reopened_by' => fn (Blueprint $table) => $table->unsignedBigInteger('reopened_by')->nullable()->after('reopened_at'),
            'reopen_reason' => fn (Blueprint $table) => $table->text('reopen_reason')->nullable()->after('reopened_by'),
        ];
    }

    /**
     * @return array{name: string|null, columns: list<string>}|null
     */
    private function reopenedByForeignKey(): ?array
    {
        return collect(Schema::getForeignKeys(self::TABLE))
            ->first(fn (array $foreignKey): bool => $foreignKey['columns'] === ['reopened_by']);
    }
};
