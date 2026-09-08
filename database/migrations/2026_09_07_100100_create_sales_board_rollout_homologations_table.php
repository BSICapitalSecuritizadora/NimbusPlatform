<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O conjunto de fatos que a Gestão revisou antes de automatizar uma Emissão.
     *
     * Existe porque "automatizado" não pode ser uma flag. Uma flag responde
     * "está ligado?"; esta tabela responde "sobre qual conjunto de
     * empreendimentos, contra qual posição legada, com quais diferenças
     * entendidas, revisado por quem, e a partir de qual competência?". Sem ela,
     * a única prova de que a decisão foi tomada seria a memória de quem clicou.
     *
     * Versionada por tentativa. Uma homologação que precise mudar de premissa --
     * outra competência inicial, outro escopo, outra aceitação de diferença --
     * não é editada: abre-se a tentativa seguinte, e a anterior continua
     * consultável com o que foi revisado na época.
     *
     * `assessment_hash` é o que separa "aprovada" de "aprovada e ainda válida".
     * Ele resume os fatos materiais avaliados -- escopo, fingerprints, posições
     * comparadas -- e **não** inclui timestamp: uma chave que muda sozinha com o
     * relógio invalidaria toda homologação a cada segundo e não detectaria
     * mudança nenhuma.
     *
     * As duas atestações de impacto são colunas separadas, com ator e data,
     * porque são afirmações de pessoas diferentes sobre consequências
     * diferentes. Um único booleano "revisado" esconderia qual das duas ficou
     * por fazer.
     *
     * FKs RESTRICT: a homologação é registro de auditoria e não evapora com a
     * Emissão. Atores são `nullOnDelete` -- perder a conta não pode apagar o
     * fato de que alguém revisou.
     */
    public function up(): void
    {
        Schema::create('sales_board_rollout_homologations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emission_id')
                ->constrained(indexName: 'sb_rollout_homologations_emission_foreign')
                ->restrictOnDelete();

            $table->unsignedInteger('attempt');
            $table->string('status', 30);

            $table->date('proposed_start_reference_month');
            $table->date('comparison_reference_month');
            $table->text('comparison_month_reason')->nullable();

            $table->boolean('auto_open_builder_review')->default(false);

            $table->char('assessment_hash', 64)->nullable();
            $table->char('construction_scope_hash', 64)->nullable();
            $table->timestamp('assessed_at')->nullable();

            $table->timestamp('guarantees_reviewed_at')->nullable();
            $table->foreignId('guarantees_reviewed_by_user_id')->nullable()
                ->constrained('users', indexName: 'sb_rollout_homologations_guarantees_by_foreign')
                ->nullOnDelete();

            $table->timestamp('monthly_report_reviewed_at')->nullable();
            $table->foreignId('monthly_report_reviewed_by_user_id')->nullable()
                ->constrained('users', indexName: 'sb_rollout_homologations_report_by_foreign')
                ->nullOnDelete();

            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()
                ->constrained('users', indexName: 'sb_rollout_homologations_approved_by_foreign')
                ->nullOnDelete();
            $table->text('approval_reason')->nullable();

            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by_user_id')->nullable()
                ->constrained('users', indexName: 'sb_rollout_homologations_rejected_by_foreign')
                ->nullOnDelete();
            $table->text('rejection_reason')->nullable();

            $table->timestamp('superseded_at')->nullable();
            $table->string('superseded_reason', 80)->nullable();

            $table->timestamp('activated_at')->nullable();

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users', indexName: 'sb_rollout_homologations_created_by_foreign')
                ->nullOnDelete();

            $table->timestamps();

            /**
             * Cada tentativa é distinguível, e nenhuma sobrescreve a anterior.
             */
            $table->unique(['emission_id', 'attempt'], 'sb_rollout_homologations_emission_attempt_unique');

            /**
             * "Qual a homologação em aberto desta Emissão?" é a pergunta que a
             * tela e o serviço de aprovação fazem.
             */
            $table->index(['emission_id', 'status'], 'sb_rollout_homologations_emission_status_index');
        });

        /**
         * O ponteiro da Emissão para a homologação vigente, agora que as duas
         * tabelas existem. Adicionado aqui em vez de na migration anterior
         * porque uma FK para uma tabela inexistente seria um ponteiro sem
         * garantia.
         */
        Schema::table('emissions', function (Blueprint $table) {
            $table->foreign('sales_board_active_homologation_id', 'emissions_active_homologation_foreign')
                ->references('id')
                ->on('sales_board_rollout_homologations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('emissions', function (Blueprint $table) {
            $table->dropForeign('emissions_active_homologation_foreign');
        });

        Schema::dropIfExists('sales_board_rollout_homologations');
    }
};
