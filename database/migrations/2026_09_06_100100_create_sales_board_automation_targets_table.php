<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O estado da automação para um empreendimento numa competência.
     *
     * Esta é a linha que torna a automação idempotente e multi-instância. O lock
     * do scheduler é otimização; a garantia é a unique daqui somada à unique de
     * `sales_board_cycles`. Duas instâncias que descubram o mesmo alvo no mesmo
     * segundo disputam esta chave, e a perdedora relê a linha da vencedora.
     *
     * Mutável de propósito, ao contrário de quase tudo no Quadro de Vendas: ela
     * é *estado atual*, não fato histórico. O histórico vive nas tentativas, que
     * são append-only. Guardar o estado como sequência de eventos obrigaria toda
     * leitura -- a tela, o retry, o discovery -- a recompor o presente a partir
     * do passado, e o presente é justamente o que a automação consulta a cada
     * hora.
     *
     * `next_attempt_at` é o que impede uma fonte incompleta de virar vinte e
     * quatro derivações por dia. Nulo significa "pode tentar agora".
     *
     * `last_blocker_codes` guarda os códigos estruturados que a prontidão já
     * produz, em JSON. A alternativa seria reparsear a mensagem para descobrir o
     * motivo, o que transformaria texto de tela em contrato de dados.
     */
    public function up(): void
    {
        Schema::create('sales_board_automation_targets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('construction_id')
                ->constrained(indexName: 'sb_automation_targets_construction_foreign')
                ->restrictOnDelete();
            $table->date('reference_month');
            $table->date('due_date');

            $table->string('status', 30);
            $table->string('satisfied_via', 20)->nullable();

            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('first_attempt_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('last_outcome_at')->nullable();

            $table->foreignId('sales_board_cycle_id')->nullable()
                ->constrained(indexName: 'sb_automation_targets_cycle_foreign')
                ->restrictOnDelete();

            $table->json('last_blocker_codes')->nullable();
            $table->text('last_blocker_message')->nullable();

            $table->string('last_error_code', 120)->nullable();
            $table->text('last_error_message')->nullable();

            $table->boolean('auto_open_builder_review')->default(false);

            $table->timestamps();

            /**
             * A identidade do alvo, e a última defesa contra duas instâncias
             * materializarem a mesma competência. Espelha a unique de
             * `sales_board_cycles`, que é o que se está protegendo.
             */
            $table->unique(
                ['construction_id', 'reference_month'],
                'sb_automation_targets_construction_month_unique',
            );

            /**
             * "Quais alvos podem tentar agora?" é a única consulta quente do
             * orquestrador, e ela entra por status e horário da próxima
             * tentativa.
             */
            $table->index(['status', 'next_attempt_at'], 'sb_automation_targets_status_next_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_board_automation_targets');
    }
};
