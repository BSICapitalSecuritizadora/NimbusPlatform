<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O Fundo de Obra (o custo previsto da obra) sai do plano: ele é da versão. A
 * V1 de cada plano já recebeu o valor (2026_10_07_200000); uma revisão de custo
 * é uma versão nova, e o fundo que valeu para as medições anteriores não é
 * reescrito.
 *
 * Por último e numa migration só para isso: a aplicação antiga, que ainda
 * atende durante o migrate do deploy, lê a coluna até aqui. A Medição nunca foi
 * usada em produção, então a janela não afeta dado real.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('measurement_plan_sets', 'construction_fund_amount')) {
            return;
        }

        $withoutVersion = DB::table('measurement_plan_sets')
            ->whereNotExists(fn ($versions) => $versions
                ->from('measurement_plan_versions')
                ->whereColumn('measurement_plan_versions.plan_set_id', 'measurement_plan_sets.id'))
            ->count();

        if ($withoutVersion > 0) {
            throw new RuntimeException("measurement_plan_sets possui {$withoutVersion} plano(s) sem versão: o Fundo de Obra deles não foi copiado. Rode antes a migration das versões.");
        }

        Schema::table('measurement_plan_sets', function (Blueprint $table): void {
            $table->dropColumn('construction_fund_amount');
        });
    }

    /**
     * O fundo volta ao plano enquanto cada plano tiver uma versão só; com
     * revisão gravada, não há um fundo do plano a restaurar.
     */
    public function down(): void
    {
        if (Schema::hasColumn('measurement_plan_sets', 'construction_fund_amount')) {
            return;
        }

        if (DB::table('measurement_plan_versions')->where('version_number', '<>', 1)->exists()) {
            throw new RuntimeException('Há revisões do plano de medição gravadas: o Fundo de Obra é de cada versão e não volta para o plano. Corrija com uma nova migration.');
        }

        Schema::table('measurement_plan_sets', function (Blueprint $table): void {
            $table->decimal('construction_fund_amount', 18, 2)->nullable()->after('is_default');
        });

        DB::table('measurement_plan_versions')
            ->where('version_number', 1)
            ->orderBy('id')
            ->get(['plan_set_id', 'construction_fund_amount'])
            ->each(fn (object $version) => DB::table('measurement_plan_sets')
                ->where('id', $version->plan_set_id)
                ->update(['construction_fund_amount' => $version->construction_fund_amount]));
    }
};
