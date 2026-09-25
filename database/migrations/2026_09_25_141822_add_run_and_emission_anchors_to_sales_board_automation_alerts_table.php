<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Duas âncoras novas, para os dois avisos que não pertencem a um alvo nem a
     * um ciclo.
     *
     * A execução interrompida é um fato da execução -- o processo morreu no
     * meio --, e a automação suspensa por escopo é um fato da Emissão inteira.
     * Pendurar qualquer um dos dois num alvo escolhido ao acaso deixaria a
     * trilha dizendo algo que não aconteceu.
     */
    public function up(): void
    {
        Schema::table('sales_board_automation_alerts', function (Blueprint $table) {
            $table->foreignId('sales_board_automation_run_id')->nullable()->after('dedupe_key')
                ->constrained(
                    table: 'sales_board_automation_runs',
                    indexName: 'sb_automation_alerts_run_foreign',
                )
                ->restrictOnDelete();

            $table->foreignId('emission_id')->nullable()->after('sales_board_automation_run_id')
                ->constrained(indexName: 'sb_automation_alerts_emission_foreign')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_board_automation_alerts', function (Blueprint $table) {
            $table->dropForeign('sb_automation_alerts_run_foreign');
            $table->dropForeign('sb_automation_alerts_emission_foreign');

            $table->dropColumn(['sales_board_automation_run_id', 'emission_id']);
        });
    }
};
