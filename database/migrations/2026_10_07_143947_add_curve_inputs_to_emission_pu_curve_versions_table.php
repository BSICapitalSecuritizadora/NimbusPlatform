<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retrato dos insumos contratuais que produziram cada versão de curva (Fase 4).
     *
     * - `curve_inputs`: representação canônica de tudo que a engine usou --
     *   parâmetros contratuais, horizonte, eventos, integralizações e a identidade da
     *   engine. É gravado na geração e nunca mais muda: é ele que a homologação
     *   aprova e é dele que a extensão diária calcula os dias novos;
     * - `curve_inputs_fingerprint`: SHA-256 da parte semântica do retrato;
     * - `curve_inputs_schema`: versão do formato do retrato (ex.:
     *   `pu-curve-inputs.v1`). Não confundir com `engine_version`, que é o
     *   algoritmo numérico;
     * - `predecessor_version_id` e `generation_context`: de qual versão oficial a
     *   nova partiu, por quê (mudança contratual, correção de índice...) e a
     *   partir de que data o contrato mudou;
     * - `contractual_change_detected_at` e `contractual_change`: a extensão diária
     *   percebeu que os insumos vivos já não são os aprovados. Mudança só no
     *   futuro deixa a oficial avançar até a véspera; no passado já gravado
     *   suspende a extensão (`extension_diverged_at`).
     *
     * Produção nunca teve curva de PU: colunas novas e nulas, sem backfill. Versões
     * anteriores a esta fase ficam sem retrato -- a extensão diária as recusa e
     * pede uma versão nova.
     */
    public function up(): void
    {
        Schema::table('emission_pu_curve_versions', function (Blueprint $table) {
            $table->string('curve_inputs_schema', 40)->nullable()->after('parameters_snapshot');
            $table->char('curve_inputs_fingerprint', 64)->nullable()->after('curve_inputs_schema');
            $table->json('curve_inputs')->nullable()->after('curve_inputs_fingerprint');
            $table->unsignedBigInteger('predecessor_version_id')->nullable()->after('curve_inputs');
            $table->json('generation_context')->nullable()->after('predecessor_version_id');
            $table->timestamp('contractual_change_detected_at')->nullable()->after('extension_failure');
            $table->json('contractual_change')->nullable()->after('contractual_change_detected_at');

            $table->index('predecessor_version_id', 'emission_pu_curve_versions_predecessor_index');
        });
    }

    public function down(): void
    {
        Schema::table('emission_pu_curve_versions', function (Blueprint $table) {
            $table->dropIndex('emission_pu_curve_versions_predecessor_index');
            $table->dropColumn([
                'curve_inputs_schema',
                'curve_inputs_fingerprint',
                'curve_inputs',
                'predecessor_version_id',
                'generation_context',
                'contractual_change_detected_at',
                'contractual_change',
            ]);
        });
    }
};
