<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Proveniência da extração assistida por IA.
     *
     * `page` e `excerpt` descrevem onde o documento sustenta a referência
     * gravada — são colunas porque a tabela e o link "abrir no documento" as
     * leem a cada linha. `value_origin` diz se o valor final saiu da IA, da IA
     * com edição humana ou só do usuário; nulo nas evidências anteriores a este
     * recurso, cuja origem não foi registrada. `extraction` guarda a sugestão
     * integral, o modelo, o horário e os campos editados depois.
     */
    public function up(): void
    {
        Schema::table('emission_pu_baseline_evidence', function (Blueprint $table) {
            $table->unsignedInteger('page')->nullable()->after('reference');
            $table->text('excerpt')->nullable()->after('page');
            $table->string('value_origin', 32)->nullable()->after('confidence');
            $table->json('extraction')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('emission_pu_baseline_evidence', function (Blueprint $table) {
            $table->dropColumn(['page', 'excerpt', 'value_origin', 'extraction']);
        });
    }
};
