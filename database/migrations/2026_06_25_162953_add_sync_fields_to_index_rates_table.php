<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Metadados de origem externa (Banco Central/SGS) para linhas sincronizadas automaticamente.
     * Aditiva e reversível: linhas manuais/projetadas mantêm os campos nulos.
     */
    public function up(): void
    {
        Schema::table('index_rates', function (Blueprint $table): void {
            $table->string('external_series_code')->nullable()->after('source_reference');
            $table->timestamp('fetched_at')->nullable()->after('external_series_code');
        });
    }

    public function down(): void
    {
        Schema::table('index_rates', function (Blueprint $table): void {
            $table->dropColumn(['external_series_code', 'fetched_at']);
        });
    }
};
