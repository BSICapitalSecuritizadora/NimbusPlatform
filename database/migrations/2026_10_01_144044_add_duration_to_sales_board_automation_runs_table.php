<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quanto a execução da automação durou, em milissegundos.
     *
     * `started_at` e `finished_at` são timestamps sem fração de segundo, e a
     * diferença entre eles transformava 0,75 s em zero e 1,96 s em um segundo.
     * O orquestrador passa a medir com `hrtime()` e gravar aqui. Nulo significa
     * "duração desconhecida": linhas anteriores a esta coluna e execuções dadas
     * como interrompidas.
     *
     * Coluna anulável sem default: `ALGORITHM=INSTANT` no MySQL, sem reconstruir
     * a tabela e sem backfill. Idempotente por `hasColumn`.
     */
    public function up(): void
    {
        if (Schema::hasColumn('sales_board_automation_runs', 'duration_ms')) {
            return;
        }

        Schema::table('sales_board_automation_runs', function (Blueprint $table): void {
            $table->unsignedInteger('duration_ms')->nullable()->after('finished_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('sales_board_automation_runs', 'duration_ms')) {
            return;
        }

        Schema::table('sales_board_automation_runs', function (Blueprint $table): void {
            $table->dropColumn('duration_ms');
        });
    }
};
