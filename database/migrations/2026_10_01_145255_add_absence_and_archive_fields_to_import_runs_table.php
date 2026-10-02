<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Colunas que a importação passa a registrar além das contagens de sempre.
     *
     * - `file_path`: a planilha confirmada, arquivada no disco privado
     *   (`imports/<tipo>/<ulid>.xlsx`), para que o histórico aponte o arquivo e
     *   não só o nome e o checksum;
     * - `records_warned`: linhas gravadas com aviso (valor ambíguo, valor fora do
     *   esperado) -- a importação não bloqueia por elas, mas registra que viu;
     * - `records_absent`: parcelas (em aberto e pagas) ou contratos cadastrados
     *   que a planilha não trouxe;
     * - `records_cancelled`, `absence_cancellation_date` e
     *   `absence_cancellation_reason`: o cancelamento das parcelas ausentes, que
     *   só acontece por decisão explícita na conferência, com data e motivo.
     *
     * Colunas nulas ou com default constante: `ALGORITHM=INSTANT` no MySQL 8.4,
     * sem backfill. Execuções antigas ficam com 0 ou NULL, e a tela mostra "—".
     * Cada coluna tem a sua guarda: o MySQL não desfaz DDL, e uma reexecução
     * depois de uma falha no meio só acrescenta o que faltou.
     */
    public function up(): void
    {
        $columns = [
            'file_path' => fn (Blueprint $table) => $table->string('file_path')->nullable(),
            'records_warned' => fn (Blueprint $table) => $table->unsignedInteger('records_warned')->default(0),
            'records_absent' => fn (Blueprint $table) => $table->unsignedInteger('records_absent')->default(0),
            'records_cancelled' => fn (Blueprint $table) => $table->unsignedInteger('records_cancelled')->default(0),
            'absence_cancellation_date' => fn (Blueprint $table) => $table->date('absence_cancellation_date')->nullable(),
            'absence_cancellation_reason' => fn (Blueprint $table) => $table->text('absence_cancellation_reason')->nullable(),
        ];

        foreach ($columns as $column => $definition) {
            if (Schema::hasColumn('import_runs', $column)) {
                continue;
            }

            Schema::table('import_runs', function (Blueprint $table) use ($definition): void {
                $definition($table);
            });
        }
    }

    public function down(): void
    {
        $columns = array_values(array_filter(
            ['file_path', 'records_warned', 'records_absent', 'records_cancelled', 'absence_cancellation_date', 'absence_cancellation_reason'],
            fn (string $column): bool => Schema::hasColumn('import_runs', $column),
        ));

        if ($columns === []) {
            return;
        }

        Schema::table('import_runs', function (Blueprint $table) use ($columns): void {
            $table->dropColumn($columns);
        });
    }
};
