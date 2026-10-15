<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quarentena das observações de índice que a sincronização recebeu e não
     * aceitou (Fase 8.1).
     *
     * Antes, o valor fora do padrão, fora do domínio, divergente do registrado
     * ou malformado virava só um contador da tentativa: a revisão não tinha o que
     * mostrar. Aqui fica a evidência -- o que a fonte informou, quando, em qual
     * tentativa e por que não entrou -- e a decisão de quem revisou. Nada daqui é
     * lido como índice realizado: aprovar passa pelo registro de observação ou
     * pela correção governada, que gravam em `index_rates`.
     *
     * `open_fingerprint` repete `fingerprint` só enquanto a revisão está
     * pendente: a unique sobre ela impede duas pendências da mesma anomalia sem
     * impedir que a mesma anomalia volte depois de superada (o MySQL não tem
     * índice único parcial; NULL não colide).
     *
     * Tabela nova e vazia: nenhuma observação existente é aceita, recusada ou
     * movida.
     */
    public function up(): void
    {
        Schema::create('index_rate_quarantines', function (Blueprint $table) {
            $table->id();
            $table->string('indexer', 20);
            $table->date('rate_date')->nullable();
            $table->string('source', 30);
            $table->string('external_series_code', 20)->nullable();
            $table->string('anomaly_type', 32);
            $table->decimal('received_value', 20, 8)->nullable();
            $table->string('raw_value', 512)->nullable();
            $table->text('reason');
            $table->decimal('reference_value', 20, 8)->nullable();
            $table->date('reference_date')->nullable();
            $table->char('fingerprint', 64);
            $table->char('open_fingerprint', 64)->nullable()->unique();
            $table->string('status', 20);
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_detected_at');
            $table->timestamp('last_detected_at');
            $table->foreignId('first_sync_attempt_id')->nullable()->constrained('pu_index_sync_attempts')->nullOnDelete();
            $table->foreignId('last_sync_attempt_id')->nullable()->constrained('pu_index_sync_attempts')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->foreignId('resulting_index_rate_id')->nullable()->constrained('index_rates')->nullOnDelete();
            $table->foreignId('resulting_correction_id')->nullable()->constrained('index_rate_corrections')->nullOnDelete();
            $table->foreignId('superseded_by_id')->nullable()->constrained('index_rate_quarantines')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'indexer', 'rate_date'], 'index_rate_quarantines_queue_index');
            $table->index(['indexer', 'rate_date', 'source'], 'index_rate_quarantines_observation_index');
            $table->index('fingerprint');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('index_rate_quarantines');
    }
};
