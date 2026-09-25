<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Calendário de DIVULGAÇÃO do índice: conta os Dias Úteis da defasagem da
     * Taxa DI. Nulo é o comportamento de antes -- a defasagem segue o
     * `calendar_code` da curva --, então nenhuma configuração existente muda.
     */
    public function up(): void
    {
        Schema::table('emission_pu_parameters', function (Blueprint $table) {
            $table->string('index_rate_calendar_code', 32)
                ->nullable()
                ->after('calendar_code');
        });
    }

    public function down(): void
    {
        Schema::table('emission_pu_parameters', function (Blueprint $table) {
            $table->dropColumn('index_rate_calendar_code');
        });
    }
};
