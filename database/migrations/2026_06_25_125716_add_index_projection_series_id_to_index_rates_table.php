<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vincula a linha projetada de `index_rates` à sua série projetada (maker/checker).
     * Linhas publicadas permanecem com FK nula. A coluna é aditiva e não altera a semântica
     * existente de `is_projected` / `forward_projection` usada pelo CDI.
     */
    public function up(): void
    {
        Schema::table('index_rates', function (Blueprint $table): void {
            $table->foreignId('index_projection_series_id')
                ->nullable()
                ->after('projection_policy')
                ->constrained('index_projection_series')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('index_rates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('index_projection_series_id');
        });
    }
};
