<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'contracts';

    private const INDEX = 'contracts_import_run_id_index';

    /**
     * Os contratos criados em lote passam a apontar a importação que os criou.
     *
     * Até aqui só as alterações ligavam ao arquivo, pelo `batch_uuid` da
     * trilha; o que uma importação criava em lote só se encontrava pelo
     * horário, que é exatamente a heurística ambígua que se quer evitar. A
     * coluna é carimbada só nos inserts em lote -- criação manual e linhas
     * antigas ficam NULL, sem backfill por horário.
     *
     * Sem FOREIGN KEY de propósito: com `foreign_key_checks=1` o InnoDB só
     * acrescenta FK com `ALGORITHM=COPY`, que reconstrói a tabela com bloqueio de
     * escrita. A importação nunca é excluída pela interface; a integridade fica
     * com a aplicação. A coluna nula entra em `ALGORITHM=INSTANT` e o índice é
     * criado online (INPLACE, `LOCK=NONE`) -- validado no MySQL 8.4 com a
     * coluna gerada STORED `occupied_unit_lock` e os uniques existentes. O
     * índice espera, porém, o lock de metadados de uma importação em
     * andamento: publique fora de uma importação em curso.
     *
     * Coluna e índice têm guardas próprias: o MySQL não desfaz DDL, e a
     * reexecução depois de uma falha no meio só cria o que faltou.
     */
    public function up(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'import_run_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unsignedBigInteger('import_run_id')->nullable();
            });
        }

        if (! Schema::hasIndex(self::TABLE, self::INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index('import_run_id', self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex(self::TABLE, self::INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
            });
        }

        if (Schema::hasColumn(self::TABLE, 'import_run_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropColumn('import_run_id');
            });
        }
    }
};
