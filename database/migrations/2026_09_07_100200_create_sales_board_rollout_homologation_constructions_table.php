<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O que foi avaliado em cada empreendimento da Emissão.
     *
     * Uma linha por empreendimento **no momento da avaliação**. É isso que
     * congela o escopo: a homologação afirma "estes N empreendimentos foram
     * revisados", e um empreendimento que entre na Emissão depois não passa a
     * estar coberto por ela retroativamente.
     *
     * As duas posições são persistidas em JSON canônico, e não em vinte colunas
     * tipadas, por uma razão concreta: elas guardam a mesma estrutura de quatro
     * baldes que já existe em três lugares do domínio, e replicá-la aqui criaria
     * a quarta cópia -- com o risco de as cinco divergirem quando um balde
     * mudasse. O JSON é versionado e sem PII: apenas unidades, valores em
     * centavos e a competência.
     *
     * `legacy_position` pode ser nulo, e nulo **não** é zero. Uma Emissão sem
     * histórico não tem posição legada, e comparar contra zero afirmaria que a
     * posição legada era zero -- que é outra coisa, e é a coisa que faria uma
     * diferença enorme parecer um match.
     *
     * `accepted_difference` é invalidado quando os fingerprints mudam: se a
     * fonte se mexeu, a diferença aceita não é mais a diferença que existe, e
     * manter o aceite deixaria a Gestão tendo aprovado um delta que ela nunca
     * viu.
     */
    public function up(): void
    {
        Schema::create('sales_board_rollout_homologation_constructions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_board_rollout_homologation_id')
                ->constrained(
                    table: 'sales_board_rollout_homologations',
                    indexName: 'sb_rollout_hc_homologation_foreign',
                )
                ->restrictOnDelete();
            $table->foreignId('construction_id')
                ->constrained(indexName: 'sb_rollout_hc_construction_foreign')
                ->restrictOnDelete();

            $table->boolean('is_ready')->default(false);
            $table->json('blocker_codes')->nullable();
            $table->text('blocker_message')->nullable();

            $table->char('source_fingerprint', 64)->nullable();
            $table->char('snapshot_fingerprint', 64)->nullable();

            $table->string('comparison_status', 30);

            $table->json('legacy_position')->nullable();
            $table->json('derived_position')->nullable();
            $table->json('position_delta')->nullable();

            $table->foreignId('legacy_sales_board_id')->nullable()
                ->constrained('sales_boards', indexName: 'sb_rollout_hc_legacy_board_foreign')
                ->nullOnDelete();
            $table->date('legacy_reference_month')->nullable();

            $table->boolean('accepted_difference')->default(false);
            $table->text('difference_reason')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by_user_id')->nullable()
                ->constrained('users', indexName: 'sb_rollout_hc_accepted_by_foreign')
                ->nullOnDelete();

            $table->boolean('has_cycle_at_or_after_start')->default(false);
            $table->boolean('has_cancelled_cycle_at_or_after_start')->default(false);
            $table->date('latest_legacy_board_month')->nullable();

            $table->timestamps();

            /**
             * Um empreendimento aparece uma vez por homologação. A unique é o
             * que impede uma reavaliação de duplicar linhas em vez de atualizar
             * as existentes.
             */
            $table->unique(
                ['sales_board_rollout_homologation_id', 'construction_id'],
                'sb_rollout_hc_homologation_construction_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_board_rollout_homologation_constructions');
    }
};
