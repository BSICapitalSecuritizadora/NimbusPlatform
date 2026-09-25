<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove dois índices que repetem o prefixo de uma unique da mesma tabela.
     *
     * `(baseline, tipo)` dos movimentos é o começo exato da unique
     * `(baseline, tipo, contrato)`, e `(emissão, papel)` dos destinatários é o
     * começo da unique `(emissão, papel, usuário)`. O InnoDB atende pelo prefixo
     * da unique tanto a consulta "movimentos deste tipo nesta versão" quanto
     * "operacionais desta Emissão", e a mesma unique continua servindo de índice
     * para a chave estrangeira da primeira coluna. O índice separado era só
     * custo de escrita e espaço, e o dos movimentos fica na tabela de maior
     * volume do Quadro.
     */
    public function up(): void
    {
        Schema::table('sales_board_cycle_movements', function (Blueprint $table) {
            $table->dropIndex('sales_board_cycle_movements_baseline_type_index');
        });

        Schema::table('sales_board_rollout_recipients', function (Blueprint $table) {
            $table->dropIndex('sb_rollout_recipients_emission_role_index');
        });
    }

    public function down(): void
    {
        Schema::table('sales_board_rollout_recipients', function (Blueprint $table) {
            $table->index(['emission_id', 'role'], 'sb_rollout_recipients_emission_role_index');
        });

        Schema::table('sales_board_cycle_movements', function (Blueprint $table) {
            $table->index(
                ['sales_board_cycle_baseline_id', 'movement_type'],
                'sales_board_cycle_movements_baseline_type_index',
            );
        });
    }
};
