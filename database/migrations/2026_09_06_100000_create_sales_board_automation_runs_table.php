<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uma execução do orquestrador da automação.
     *
     * A trilha da automação tem três tabelas porque responde três perguntas
     * diferentes, e uma só as confundiria: a execução diz "o scheduler rodou e
     * o que ele viu", o alvo diz "qual o estado desta competência agora", e a
     * tentativa diz "o que aconteceu em cada investida". Sem a execução não há
     * como saber que o scheduler passou uma noite inteira sem rodar -- só que
     * nenhum alvo mudou, que é indistinguível de tudo estar em ordem.
     *
     * `as_of_date` é persistido em vez de derivado de `started_at`: a decisão
     * de competência é tomada no fuso de negócio, e uma execução das 21h em
     * São Paulo pertence a um dia civil diferente do dia UTC do timestamp.
     * Guardar a data que a execução efetivamente usou é o que torna a decisão
     * reproduzível um ano depois.
     *
     * `instance_key` não identifica máquina para efeito de correção -- a
     * correção é do banco. Ele existe para a investigação: quando duas
     * execuções competem, saber que vieram de processos diferentes é a
     * diferença entre "duas instâncias" e "um laço em duplicidade".
     */
    public function up(): void
    {
        Schema::create('sales_board_automation_runs', function (Blueprint $table) {
            $table->id();

            $table->string('trigger', 20);
            $table->string('status', 30);

            $table->date('as_of_date');
            $table->date('latest_due_reference_month')->nullable();

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            $table->unsignedInteger('targets_discovered')->default(0);
            $table->unsignedInteger('targets_attempted')->default(0);
            $table->unsignedInteger('generated_count')->default(0);
            $table->unsignedInteger('existing_count')->default(0);
            $table->unsignedInteger('blocked_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('alerts_sent')->default(0);
            $table->unsignedInteger('alerts_deduped')->default(0);

            $table->string('instance_key', 64)->nullable();
            $table->text('failure_message')->nullable();

            $table->timestamps();

            /**
             * A tela operacional abre sempre pela mesma pergunta -- "as últimas
             * execuções" -- e o monitoramento pergunta "houve execução recente
             * que terminou mal". As duas entram por aqui.
             */
            $table->index(['started_at'], 'sb_automation_runs_started_index');
            $table->index(['status', 'started_at'], 'sb_automation_runs_status_started_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_board_automation_runs');
    }
};
