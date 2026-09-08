<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O livro-razão dos avisos, e o que impede o mesmo alerta de sair
     * vinte e quatro vezes por dia.
     *
     * A `dedupe_key` é determinística e calculada a partir do que identifica a
     * condição -- entidade, tipo, destinatário e a janela relevante. Nada de
     * timestamp do instante: uma chave que muda a cada segundo deduplica
     * exatamente nada, e o scheduler roda de hora em hora.
     *
     * A unique é a proteção real. O fluxo é `insertOrIgnore` seguido de envio:
     * quem inseriu envia, quem colidiu conta como deduplicado. Isso dá
     * at-least-once honesto -- se o envio falhar depois de inserir, a linha é
     * removida e a próxima execução tenta de novo. Exactly-once com um canal
     * externo não existe, e fingir que existe só esconderia o alerta perdido.
     *
     * As quatro âncoras são anuláveis porque cada tipo de alerta se refere a uma
     * coisa diferente: bloqueio de geração aponta o alvo, lembrete de validação
     * aponta a revisão da construtora. Uma tabela por tipo seria o mesmo dado em
     * quatro lugares.
     */
    public function up(): void
    {
        Schema::create('sales_board_automation_alerts', function (Blueprint $table) {
            $table->id();

            $table->string('alert_type', 40);
            $table->char('dedupe_key', 64);

            $table->foreignId('sales_board_automation_target_id')->nullable()
                ->constrained(
                    table: 'sales_board_automation_targets',
                    indexName: 'sb_automation_alerts_target_foreign',
                )
                ->restrictOnDelete();
            $table->foreignId('sales_board_cycle_id')->nullable()
                ->constrained(indexName: 'sb_automation_alerts_cycle_foreign')
                ->restrictOnDelete();
            $table->foreignId('sales_board_builder_review_id')->nullable()
                ->constrained(
                    table: 'sales_board_builder_reviews',
                    indexName: 'sb_automation_alerts_builder_review_foreign',
                )
                ->restrictOnDelete();
            $table->foreignId('sales_board_management_review_id')->nullable()
                ->constrained(
                    table: 'sales_board_management_reviews',
                    indexName: 'sb_automation_alerts_management_review_foreign',
                )
                ->restrictOnDelete();

            $table->foreignId('recipient_user_id')->nullable()
                ->constrained('users', indexName: 'sb_automation_alerts_recipient_foreign')
                ->nullOnDelete();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique('dedupe_key', 'sb_automation_alerts_dedupe_unique');
            $table->index(['alert_type', 'created_at'], 'sb_automation_alerts_type_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_board_automation_alerts');
    }
};
