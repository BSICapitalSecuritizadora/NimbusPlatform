<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Falha da extensão diária gravada na própria versão: pré-requisito
     * bloqueado ou erro de cálculo. É o que separa "a curva oficial ainda não foi
     * estendida" de "a extensão tentou e não conseguiu". A próxima extensão que
     * rodar limpa os dois campos. Colunas novas e nulas: nenhuma linha existente
     * é tocada.
     */
    public function up(): void
    {
        Schema::table('emission_pu_curve_versions', function (Blueprint $table) {
            $table->timestamp('extension_failed_at')->nullable()->after('extension_divergence');
            $table->json('extension_failure')->nullable()->after('extension_failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('emission_pu_curve_versions', function (Blueprint $table) {
            $table->dropColumn(['extension_failed_at', 'extension_failure']);
        });
    }
};
