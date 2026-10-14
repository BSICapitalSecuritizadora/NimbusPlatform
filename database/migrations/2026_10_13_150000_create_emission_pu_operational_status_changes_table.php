<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Livro de ativação operacional do PU (Fase 7): cada decisão de ativar ou
     * suspender a operação do PU de uma emissão, só de inclusão. A situação
     * vigente é a última decisão; sem decisão nenhuma, a emissão está inativa.
     *
     * Tabela nova e VAZIA: nenhuma emissão é ativada por esta migration nem pelo
     * deploy -- configurada, `active` e com parâmetros CDI continua fora do
     * pipeline que grava curva até uma decisão autorizada.
     *
     * A chave para a emissão desce em cascata: a decisão sobre uma emissão sem
     * histórico financeiro governado sai com ela (a trilha protegida do
     * Activitylog guarda a decisão); a emissão COM histórico governado não sai de
     * jeito nenhum (âncora RESTRICT, migration seguinte).
     */
    public function up(): void
    {
        Schema::create('emission_pu_operational_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emission_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20);
            $table->string('previous_status', 20);
            $table->text('reason');
            $table->string('approval_reference', 255)->nullable();
            $table->string('readiness_status', 40)->nullable();
            $table->json('readiness_snapshot')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decided_via', 20);
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->index(['emission_id', 'id'], 'pu_operational_status_changes_emission_idx');
        });
    }

    /**
     * Desfazer apaga as decisões e, com elas, toda ativação: o portão volta a
     * recusar todas as emissões (falha fechada). Nenhuma curva, obrigação ou
     * liquidação é tocada.
     */
    public function down(): void
    {
        Schema::dropIfExists('emission_pu_operational_status_changes');
    }
};
