<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O que o alvo precisava saber para sobreviver a um processo morto e a um
     * perímetro que muda.
     *
     * `consecutive_failure_count` separa falha técnica de bloqueio. O
     * `attempt_count` soma tudo -- bloqueios diários inclusive --, e usá-lo no
     * backoff jogava a primeira falha técnica de um alvo bloqueado havia dias
     * direto no último degrau, e disparava a escalação de "falha repetida" na
     * primeira falha. O contador novo zera em bloqueio e em sucesso.
     *
     * `in_flight_run_id` é o marcador da tentativa em andamento, gravado e
     * commitado **antes** da geração. Um processo que morre no meio (falta de
     * memória, deploy, reinício) não desfaz o marcador: a execução seguinte o
     * encontra, registra a tentativa como interrompida e o backoff passa a
     * valer. Sem ele, a transação desfeita apagava a própria tentativa, e o
     * alvo pesado voltava primeiro da fila a cada hora, sem contar nada.
     *
     * As colunas de encerramento guardam o último encerramento: um alvo que
     * saiu do perímetro da automação (retorno ao legado, escopo suspenso) deixa
     * de ser tentado e de ser lembrado, e quem, quando e por quê fica aqui.
     * Anuláveis porque a maioria dos alvos nunca é encerrada.
     */
    public function up(): void
    {
        Schema::table('sales_board_automation_targets', function (Blueprint $table) {
            $table->unsignedInteger('consecutive_failure_count')->default(0)->after('attempt_count');

            $table->foreignId('in_flight_run_id')->nullable()->after('consecutive_failure_count')
                ->constrained(
                    table: 'sales_board_automation_runs',
                    indexName: 'sb_automation_targets_in_flight_run_foreign',
                )
                ->restrictOnDelete();

            $table->timestamp('closed_at')->nullable()->after('last_error_message');
            $table->string('closure_reason', 40)->nullable()->after('closed_at');
            $table->text('closure_message')->nullable()->after('closure_reason');

            $table->foreignId('closed_by_user_id')->nullable()->after('closure_message')
                ->constrained('users', indexName: 'sb_automation_targets_closed_by_foreign')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_board_automation_targets', function (Blueprint $table) {
            $table->dropForeign('sb_automation_targets_in_flight_run_foreign');
            $table->dropForeign('sb_automation_targets_closed_by_foreign');

            $table->dropColumn([
                'consecutive_failure_count',
                'in_flight_run_id',
                'closed_at',
                'closure_reason',
                'closure_message',
                'closed_by_user_id',
            ]);
        });
    }
};
