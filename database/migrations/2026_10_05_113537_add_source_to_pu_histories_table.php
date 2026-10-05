<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Origem de cada linha do Histórico de PU: `import` (planilha) ou `manual`
     * (lançamento na tela). Nula é linha anterior a esta coluna, de origem não
     * registrada -- até a Fase 2 de governança a geração de curva também gravava
     * aqui. Nenhuma linha existente é reclassificada: `EmissionPuReader` decide
     * pela data da linha se ela vale como PU de uma emissão governada.
     */
    public function up(): void
    {
        Schema::table('pu_histories', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('unit_value');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pu_histories', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
