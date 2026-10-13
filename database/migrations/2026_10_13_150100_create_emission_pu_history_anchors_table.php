<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Âncora do histórico financeiro governado do PU (Fase 7).
     *
     * Quase todo o PU desce em cascata a partir de `emissions` -- versões, linhas
     * da curva, retratos de insumos, eventos, parâmetros, obrigações, cálculos,
     * componentes, conciliação --, e apagar a emissão (pelo model, em massa ou por
     * `DELETE` direto) levava junto a curva homologada e o esperado das
     * obrigações. Em vez de reescrever as cascatas de cada relação, uma linha por
     * emissão com chave RESTRICT: enquanto ela existir, a emissão não sai, e sem a
     * emissão sair nenhuma cascata dispara. A emissão sem histórico governado
     * continua com as regras de exclusão de antes.
     *
     * A âncora nasce na mesma transação da primeira homologação. Esta migration
     * também ancora as emissões que JÁ tenham histórico governado (curva
     * homologada em algum momento, obrigação, liquidação, conflito ou promoção):
     * só acrescenta a proteção -- nenhum valor financeiro, curva, obrigação ou
     * liquidação é criado ou alterado, nada é ativado. Numa produção ainda sem
     * operação do PU, não grava linha nenhuma.
     */
    public function up(): void
    {
        Schema::create('emission_pu_history_anchors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emission_id')->unique()->constrained()->restrictOnDelete();
            $table->string('reason', 40);
            $table->foreignId('curve_version_id')->nullable()->constrained('emission_pu_curve_versions')->restrictOnDelete();
            $table->foreignId('anchored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('anchored_at');
            $table->timestamps();
        });

        $governed = collect()
            ->merge(DB::table('emission_pu_curve_versions')->whereNotNull('homologated_at')->distinct()->pluck('emission_id'))
            ->merge(DB::table('emission_pu_obligations')->distinct()->pluck('emission_id'))
            ->merge(DB::table('emission_pu_settlements')->distinct()->pluck('emission_id'))
            ->merge(DB::table('emission_pu_settlement_conflicts')->distinct()->pluck('emission_id'))
            ->merge(DB::table('emission_pu_curve_promotions')->distinct()->pluck('emission_id'))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();
        $now = now();

        foreach ($governed as $emissionId) {
            DB::table('emission_pu_history_anchors')->insert([
                'emission_id' => $emissionId,
                'reason' => 'preexisting_governed_history',
                'curve_version_id' => DB::table('emission_pu_curve_versions')
                    ->where('emission_id', $emissionId)
                    ->whereNotNull('homologated_at')
                    ->min('id'),
                'anchored_by' => null,
                'anchored_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Desfazer tiraria a proteção do histórico financeiro governado em silêncio.
     * Com âncora gravada, o rollback recusa: tirar a proteção de uma emissão com
     * histórico governado é decisão explícita, fora de uma migration. Sem âncora
     * nenhuma (nada governado), a tabela sai.
     */
    public function down(): void
    {
        if (Schema::hasTable('emission_pu_history_anchors') && DB::table('emission_pu_history_anchors')->exists()) {
            throw new RuntimeException('Rollback recusado: há emissões com histórico financeiro governado do PU ancorado. Remover a proteção exige decisão explícita fora da migration.');
        }

        Schema::dropIfExists('emission_pu_history_anchors');
    }
};
