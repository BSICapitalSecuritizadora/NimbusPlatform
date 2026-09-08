<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dossiê de promoção operacional da curva de PU (2B.5.18).
 *
 * A decisão que troca a curva vigente altera estado operacional e por isso tem
 * representação de domínio persistida — o Activitylog continua sendo auditoria
 * complementar, não a fonte da decisão.
 *
 * O artefato é append-only: um pedido nunca é sobrescrito nem apagado. O único
 * bloco mutável é a decisão de review (uma vez) e, depois dela, a execução (uma
 * vez). A unicidade por candidate materializa a regra conservadora "promoção
 * rejeitada é final para aquela candidate": uma nova tentativa exige uma nova
 * candidate governada, e não um resubmit que contorne o revisor.
 *
 * Toda FK é RESTRICT: promoção é histórico financeiro e não pode ser silenciada
 * por cascade em usuário, emissão, versão de curva ou dossiê externo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('emission_pu_curve_promotions')) {
            return;
        }

        Schema::create('emission_pu_curve_promotions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('emission_id')->constrained()->restrictOnDelete();
            $table->foreignId('candidate_curve_version_id')
                ->constrained('emission_pu_curve_versions', 'id', 'pu_curve_promotion_candidate_fk')
                ->restrictOnDelete();
            $table->foreignId('previous_operational_curve_version_id')
                ->nullable()
                ->constrained('emission_pu_curve_versions', 'id', 'pu_curve_promotion_previous_fk')
                ->restrictOnDelete();
            $table->foreignId('external_validation_id')
                ->constrained('emission_pu_external_validations', 'id', 'pu_curve_promotion_validation_fk')
                ->restrictOnDelete();
            $table->string('calculation_version', 50);
            $table->char('candidate_checksum', 64);
            $table->char('input_fingerprint', 64);
            $table->char('benchmark_dataset_sha256', 64);
            $table->char('comparison_sha256', 64);
            $table->unsignedInteger('rows_count');
            $table->string('status', 20)->default('pending_review');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->foreignId('executed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('promoted_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['candidate_curve_version_id'],
                'pu_curve_promotion_candidate_unique',
            );
            $table->index(
                ['emission_id', 'status', 'id'],
                'pu_curve_promotion_emission_status_index',
            );
            $table->index(
                ['emission_id', 'promoted_at'],
                'pu_curve_promotion_executed_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emission_pu_curve_promotions');
    }
};
