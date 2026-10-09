<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Obrigações financeiras, cálculo esperado versionado, liquidação e
     * conciliação do PU (Fase 5). Três fatos diferentes, em tabelas diferentes:
     *
     * - `emission_pu_obligations`: a obrigação econômica, com identidade estável
     *   (emissão, natureza, data contratual, sequência) -- UNIQUE no banco. Uma
     *   versão nova da curva não cria outra obrigação para o mesmo pagamento;
     * - `emission_pu_obligation_calculations` + `..._components`: o valor esperado
     *   que cada curva oficial calculou, por componente, imutável. Só a marcação
     *   de substituição muda; um cálculo vigente por obrigação (coluna gerada
     *   `current_marker` na UNIQUE);
     * - `emission_pu_settlements`: o livro de liquidações, só de inclusão.
     *   Correção e estorno são lançamentos novos que apontam o anterior
     *   (`predecessor_id` UNIQUE: um sucessor por lançamento). Uma liquidação
     *   ativa por obrigação (coluna gerada `active_obligation_marker` UNIQUE). A
     *   chave de ingestão (`origem|referência externa`) é UNIQUE: a mesma
     *   mensagem nunca vira duas liquidações;
     * - `emission_pu_settlement_conflicts`: dados que chegaram e não puderam ser
     *   aplicados sem decisão humana;
     * - `emission_pu_reconciliations`: histórico de resultados da conciliação, só
     *   de inclusão, com o cálculo e a liquidação usados.
     *
     * Liquidação e conflito são evidência financeira: as chaves estrangeiras para
     * eles são RESTRICT, e uma emissão com liquidação não pode ser apagada em
     * cascata. O que é derivado (obrigação, cálculo, conciliação) acompanha a
     * emissão.
     *
     * As colunas geradas são virtuais pelo mesmo motivo da Fase 4 (SQLite e MySQL
     * aceitam UNIQUE sobre elas). Tabelas novas e vazias: nada é migrado, e
     * nenhuma liquidação histórica é fabricada.
     */
    public function up(): void
    {
        Schema::create('emission_pu_obligations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emission_id')->constrained()->cascadeOnDelete();
            $table->string('obligation_type', 40);
            $table->date('contractual_date');
            $table->unsignedInteger('sequence')->default(1);
            $table->date('due_date')->nullable();
            $table->string('lifecycle_status', 20)->default('active');
            $table->timestamp('superseded_at')->nullable();
            $table->string('supersession_reason', 60)->nullable();
            $table->foreignId('superseded_by_obligation_id')->nullable()->constrained('emission_pu_obligations')->nullOnDelete();
            $table->json('source_event_ids')->nullable();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('calculation_state', 40);
            $table->text('calculation_state_reason')->nullable();
            $table->string('settlement_state', 20)->default('unsettled');
            $table->string('reconciliation_status', 30)->default('pending');
            $table->timestamps();

            $table->unique(['emission_id', 'obligation_type', 'contractual_date', 'sequence'], 'emission_pu_obligations_identity_unique');
            $table->index(['emission_id', 'due_date'], 'emission_pu_obligations_due_index');
            $table->index(['reconciliation_status', 'due_date'], 'emission_pu_obligations_reconciliation_index');
            $table->index(['calculation_state'], 'emission_pu_obligations_calculation_index');
        });

        Schema::create('emission_pu_obligation_calculations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('obligation_id')->constrained('emission_pu_obligations')->cascadeOnDelete();
            $table->foreignId('emission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curve_version_id')->nullable()->constrained('emission_pu_curve_versions')->nullOnDelete();
            $table->string('calculation_version', 40)->nullable();
            $table->string('status', 20);
            $table->date('due_date');
            $table->decimal('total_amount', 15, 2)->nullable();
            $table->string('components_fingerprint', 64);
            $table->timestamp('calculated_at');
            $table->timestamp('superseded_at')->nullable();
            $table->foreignId('superseded_by_calculation_id')->nullable()
                ->constrained('emission_pu_obligation_calculations', 'id', 'emission_pu_obligation_calc_superseded_by_foreign')->nullOnDelete();
            $table->string('supersession_reason', 60)->nullable();
            $table->unsignedTinyInteger('current_marker')
                ->nullable()
                ->virtualAs('case when superseded_at is null then 1 else null end');
            $table->timestamps();

            $table->unique(['obligation_id', 'current_marker'], 'emission_pu_obligation_calc_current_unique');
            $table->index(['obligation_id', 'calculated_at'], 'emission_pu_obligation_calc_history_index');
        });

        Schema::create('emission_pu_obligation_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calculation_id')->constrained('emission_pu_obligation_calculations')->cascadeOnDelete();
            $table->string('component', 40);
            $table->string('owner', 30);
            $table->string('status', 20);
            $table->decimal('amount', 15, 2)->nullable();
            $table->decimal('unit_amount', 24, 16)->nullable();
            $table->decimal('quantity', 18, 4)->nullable();
            $table->text('reason')->nullable();
            $table->json('source')->nullable();
            $table->timestamps();

            $table->unique(['calculation_id', 'component', 'owner'], 'emission_pu_obligation_component_unique');
        });

        Schema::table('emission_pu_obligations', function (Blueprint $table) {
            $table->foreignId('current_calculation_id')->nullable()->after('payment_id')
                ->constrained('emission_pu_obligation_calculations')->nullOnDelete();
        });

        Schema::create('emission_pu_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emission_id')->constrained()->restrictOnDelete();
            $table->foreignId('obligation_id')->constrained('emission_pu_obligations')->restrictOnDelete();
            $table->string('entry_type', 20);
            $table->string('status', 20);
            $table->foreignId('predecessor_id')->nullable()->constrained('emission_pu_settlements')->restrictOnDelete();
            $table->foreignId('superseded_by_settlement_id')->nullable()->constrained('emission_pu_settlements')->restrictOnDelete();
            $table->timestamp('superseded_at')->nullable();
            $table->date('settlement_date')->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->char('currency', 3)->default('BRL');
            $table->json('components')->nullable();
            $table->string('source', 20);
            $table->string('external_reference', 120)->nullable();
            $table->string('ingestion_key', 160)->nullable();
            $table->string('payload_fingerprint', 64)->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('expected_calculation_id')->nullable()->constrained('emission_pu_obligation_calculations')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recorded_via', 60)->nullable();
            $table->timestamp('recorded_at');
            $table->unsignedBigInteger('active_obligation_marker')
                ->nullable()
                ->virtualAs("case when status = 'active' then obligation_id else null end");
            $table->timestamps();

            $table->unique('predecessor_id', 'emission_pu_settlements_predecessor_unique');
            $table->unique('ingestion_key', 'emission_pu_settlements_ingestion_unique');
            $table->unique('active_obligation_marker', 'emission_pu_settlements_active_unique');
            $table->index(['obligation_id', 'status'], 'emission_pu_settlements_obligation_index');
            $table->index(['source', 'external_reference'], 'emission_pu_settlements_reference_index');
        });

        Schema::create('emission_pu_settlement_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emission_id')->constrained()->restrictOnDelete();
            $table->foreignId('obligation_id')->constrained('emission_pu_obligations')->restrictOnDelete();
            $table->foreignId('existing_settlement_id')->nullable()->constrained('emission_pu_settlements')->restrictOnDelete();
            $table->string('kind', 40);
            $table->string('status', 20)->default('open');
            $table->string('source', 20);
            $table->string('external_reference', 120)->nullable();
            $table->json('incoming_payload');
            $table->string('payload_fingerprint', 64);
            $table->timestamp('detected_at');
            $table->foreignId('detected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('detected_via', 60)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_reason')->nullable();
            $table->foreignId('resolution_settlement_id')->nullable()
                ->constrained('emission_pu_settlements', 'id', 'emission_pu_settlement_conflicts_resolution_foreign')->restrictOnDelete();
            $table->timestamps();

            $table->index(['obligation_id', 'status'], 'emission_pu_settlement_conflicts_open_index');
        });

        Schema::create('emission_pu_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('obligation_id')->constrained('emission_pu_obligations')->cascadeOnDelete();
            $table->foreignId('emission_id')->constrained()->cascadeOnDelete();
            $table->string('status', 30);
            $table->string('reason', 60)->nullable();
            $table->foreignId('calculation_id')->nullable()->constrained('emission_pu_obligation_calculations')->nullOnDelete();
            $table->foreignId('settlement_id')->nullable()->constrained('emission_pu_settlements')->nullOnDelete();
            $table->decimal('expected_total', 15, 2)->nullable();
            $table->decimal('actual_total', 15, 2)->nullable();
            $table->decimal('difference', 15, 2)->nullable();
            $table->json('divergence')->nullable();
            $table->string('result_fingerprint', 64);
            $table->string('trigger', 60);
            $table->timestamp('evaluated_at');
            $table->timestamps();

            $table->index(['obligation_id', 'id'], 'emission_pu_reconciliations_history_index');
        });

        Schema::table('emission_pu_obligations', function (Blueprint $table) {
            $table->foreignId('latest_reconciliation_id')->nullable()->after('reconciliation_status')
                ->constrained('emission_pu_reconciliations')->nullOnDelete();
        });
    }

    /**
     * Desfaz a estrutura. Com liquidação registrada o rollback falha de propósito
     * (as chaves RESTRICT protegem a evidência financeira) -- apague-as antes, de
     * forma consciente, se for mesmo o caso.
     */
    public function down(): void
    {
        Schema::table('emission_pu_obligations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('latest_reconciliation_id');
            $table->dropConstrainedForeignId('current_calculation_id');
        });

        Schema::dropIfExists('emission_pu_reconciliations');
        Schema::dropIfExists('emission_pu_settlement_conflicts');
        Schema::dropIfExists('emission_pu_settlements');
        Schema::dropIfExists('emission_pu_obligation_components');
        Schema::dropIfExists('emission_pu_obligation_calculations');
        Schema::dropIfExists('emission_pu_obligations');
    }
};
