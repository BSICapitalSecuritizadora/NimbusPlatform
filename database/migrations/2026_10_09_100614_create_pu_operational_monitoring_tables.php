<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Observabilidade e recuperação operacional do PU (Fase 6). Quatro registros
     * operacionais, nenhum fato financeiro:
     *
     * - `pu_obligation_refresh_requests`: pedido durável de recompor as obrigações
     *   de uma emissão, gravado na MESMA transação do fato que o provoca (correção
     *   de índice, divergência da oficial, insumo contratual, cronograma informado),
     *   sem chave estrangeira para não pegar trava na emissão.
     *   Só de inclusão para quem pede -- nenhuma trava disputada entre pedidos --; quem
     *   executa reserva (`claim_token` + prazo de concessão), executa e registra o
     *   resultado. Um processo que morre depois do commit deixa o pedido pendente, e
     *   a varredura o encontra;
     * - `pu_operational_incidents`: condição operacional que pede ação, com
     *   identidade estável (`incident_key`). Um incidente aberto por identidade
     *   (coluna gerada `open_key` na UNIQUE): dois monitores simultâneos não abrem
     *   dois. Resolvido fica como histórico; se a condição volta, nasce outro
     *   ligado a ele (`recurrence_of_id`);
     * - `pu_monitor_runs`: cada execução do monitor e o que ela conseguiu
     *   verificar -- "não rodou" e "rodou em parte" nunca parecem "tudo certo";
     * - `pu_index_sync_attempts`: cada tentativa de sincronização de índice, com o
     *   resultado classificado (respondeu sem observação nova é diferente de trouxe
     *   a esperada) e o erro higienizado.
     *
     * Tabelas novas e vazias: nada é migrado, nenhuma curva, liquidação ou
     * incidente histórico é fabricado. As chaves para emissão, obrigação e conflito
     * são SET NULL nos incidentes (o histórico operacional sobrevive).
     */
    public function up(): void
    {
        Schema::create('pu_obligation_refresh_requests', function (Blueprint $table) {
            $table->id();
            // Sem chave estrangeira de propósito: o pedido é gravado DENTRO da
            // transação de quem pede, que pode já segurar a trava do parâmetro, do
            // evento ou do índice; no InnoDB a checagem da FK pegaria trava
            // compartilhada na emissão e inverteria a ordem emissão → insumos da
            // extensão e da homologação (deadlock provado no MySQL). Emissão
            // apagada vira pedido sem efeito: o executor o encerra.
            $table->unsignedBigInteger('emission_id');
            $table->string('trigger', 60);
            $table->string('status', 20)->default('pending');
            $table->uuid('correlation_id');
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->timestamp('requested_at');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->uuid('claim_token')->nullable();
            $table->string('claimed_via', 20)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('claim_expires_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->string('last_error_category', 40)->nullable();
            $table->string('last_error_class', 190)->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('satisfied_by_request_id')->nullable()
                ->constrained('pu_obligation_refresh_requests', 'id', 'pu_obligation_refresh_satisfied_by_foreign')->nullOnDelete();
            $table->json('result')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at'], 'pu_obligation_refresh_due_index');
            $table->index(['emission_id', 'status'], 'pu_obligation_refresh_emission_index');
            $table->index('claim_token', 'pu_obligation_refresh_claim_index');
        });

        Schema::create('pu_operational_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('incident_key', 191);
            $table->string('type', 60);
            $table->string('check_name', 20);
            $table->string('severity', 10);
            $table->string('status', 20)->default('active');
            $table->foreignId('emission_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('curve_version_id')->nullable()->constrained('emission_pu_curve_versions')->nullOnDelete();
            $table->foreignId('obligation_id')->nullable()->constrained('emission_pu_obligations')->nullOnDelete();
            $table->foreignId('settlement_conflict_id')->nullable()->constrained('emission_pu_settlement_conflicts')->nullOnDelete();
            $table->string('indexer', 20)->nullable();
            $table->date('business_date')->nullable();
            $table->text('reason');
            $table->json('context')->nullable();
            $table->timestamp('first_detected_at');
            $table->timestamp('last_detected_at');
            $table->unsignedInteger('detection_count')->default(1);
            // Execução do monitor que viu a condição por último. Ids crescem com o
            // início da execução: é a guarda da corrida entre resolver e reincidir
            // (o timestamp, em segundos no MySQL, empata dentro do mesmo segundo).
            $table->unsignedBigInteger('last_detected_run_id')->nullable();
            $table->foreignId('recurrence_of_id')->nullable()
                ->constrained('pu_operational_incidents', 'id', 'pu_operational_incidents_recurrence_foreign')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('acknowledgement_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 40)->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->string('notified_severity', 10)->nullable();
            $table->text('notification_error')->nullable();
            $table->string('open_key', 191)
                ->nullable()
                ->virtualAs('case when resolved_at is null then incident_key else null end');
            $table->timestamps();

            $table->unique('open_key', 'pu_operational_incidents_open_unique');
            $table->index(['status', 'severity'], 'pu_operational_incidents_status_index');
            $table->index(['incident_key', 'id'], 'pu_operational_incidents_history_index');
            $table->index(['check_name', 'resolved_at'], 'pu_operational_incidents_check_index');
        });

        Schema::create('pu_monitor_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20)->default('running');
            $table->string('trigger', 20);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->json('checks')->nullable();
            $table->unsignedInteger('conditions_count')->default(0);
            $table->unsignedInteger('incidents_opened')->default(0);
            $table->unsignedInteger('incidents_updated')->default(0);
            $table->unsignedInteger('incidents_resolved')->default(0);
            $table->unsignedInteger('notifications_sent')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['status', 'finished_at'], 'pu_monitor_runs_status_index');
        });

        Schema::create('pu_index_sync_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('indexer', 20);
            $table->string('source', 30);
            $table->date('requested_from');
            $table->date('requested_to');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('outcome', 30)->nullable();
            $table->string('failure_category', 40)->nullable();
            $table->unsignedInteger('fetched')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('conflicts')->default(0);
            $table->unsignedInteger('invalid_entries')->default(0);
            $table->unsignedSmallInteger('blocks_total')->default(0);
            $table->unsignedSmallInteger('blocks_failed')->default(0);
            $table->date('latest_observation_date')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['indexer', 'started_at'], 'pu_index_sync_attempts_indexer_index');
        });
    }

    /**
     * Desfaz a estrutura. São registros operacionais (pedidos, incidentes,
     * execuções e tentativas), nenhum fato financeiro: os fatos continuam nas
     * tabelas da Fase 5 e no livro de correções de índice.
     */
    public function down(): void
    {
        Schema::dropIfExists('pu_index_sync_attempts');
        Schema::dropIfExists('pu_monitor_runs');
        Schema::dropIfExists('pu_operational_incidents');
        Schema::dropIfExists('pu_obligation_refresh_requests');
    }
};
