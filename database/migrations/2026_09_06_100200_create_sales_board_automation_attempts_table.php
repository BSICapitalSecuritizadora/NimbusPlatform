<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O que aconteceu em cada investida sobre um alvo.
     *
     * Append-only. É a única parte da automação que responde "por que este
     * empreendimento levou onze dias para gerar" -- o alvo só sabe o presente, e
     * sobrescrever o motivo anterior a cada tentativa apagaria justamente a
     * sequência que explica o atraso.
     *
     * `reason_code` guarda código funcional, nunca stack trace: o código é o que
     * agrupa uma métrica e o que a tela traduz. Rastreamento técnico é assunto
     * do log de aplicação, que tem retenção e controle de acesso próprios --
     * copiá-lo para uma tabela de negócio espalharia detalhe de infraestrutura
     * por um lugar que ninguém trata como sensível.
     */
    public function up(): void
    {
        Schema::create('sales_board_automation_attempts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sales_board_automation_run_id')
                ->constrained(
                    table: 'sales_board_automation_runs',
                    indexName: 'sb_automation_attempts_run_foreign',
                )
                ->restrictOnDelete();
            $table->foreignId('sales_board_automation_target_id')
                ->constrained(
                    table: 'sales_board_automation_targets',
                    indexName: 'sb_automation_attempts_target_foreign',
                )
                ->restrictOnDelete();

            $table->unsignedInteger('attempt_number');
            $table->string('outcome', 20);

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            $table->foreignId('sales_board_cycle_id')->nullable()
                ->constrained(indexName: 'sb_automation_attempts_cycle_foreign')
                ->restrictOnDelete();

            $table->string('reason_code', 120)->nullable();
            $table->text('reason_message')->nullable();

            $table->timestamp('created_at')->nullable();

            /**
             * A leitura é sempre "as tentativas deste alvo, na ordem". A da
             * execução entra pelo índice que o InnoDB cria para a própria chave
             * estrangeira.
             */
            $table->index(
                ['sales_board_automation_target_id', 'attempt_number'],
                'sb_automation_attempts_target_number_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_board_automation_attempts');
    }
};
