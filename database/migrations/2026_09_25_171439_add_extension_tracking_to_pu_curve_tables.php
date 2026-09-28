<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extensão diária da curva: a rotina anexa só os dias novos à versão vigente
     * em vez de gravar a curva inteira de novo. A versão guarda quantas linhas
     * recebeu depois da geração e, quando o passado recalculado deixa de bater
     * numa curva governada, a divergência que suspendeu a extensão. Cada linha
     * anexada leva a data em que entrou -- é o que separa o trecho revisado do
     * trecho apenas realizado.
     */
    public function up(): void
    {
        Schema::table('emission_pu_curve_versions', function (Blueprint $table) {
            $table->unsignedInteger('extended_rows_count')->default(0)->after('rows_count');
            $table->timestamp('last_extended_at')->nullable()->after('extended_rows_count');
            $table->timestamp('extension_diverged_at')->nullable()->after('last_extended_at');
            $table->json('extension_divergence')->nullable()->after('extension_diverged_at');
        });

        Schema::table('emission_pu_daily_curves', function (Blueprint $table) {
            $table->timestamp('extended_at')->nullable()->after('calculation_version');
        });
    }

    public function down(): void
    {
        Schema::table('emission_pu_daily_curves', function (Blueprint $table) {
            $table->dropColumn('extended_at');
        });

        Schema::table('emission_pu_curve_versions', function (Blueprint $table) {
            $table->dropColumn([
                'extended_rows_count',
                'last_extended_at',
                'extension_diverged_at',
                'extension_divergence',
            ]);
        });
    }
};
