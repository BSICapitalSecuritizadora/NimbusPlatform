<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Campos para distinguir número-índice PUBLICADO de PROJETADO (política de projeção de mercado).
     * A engine IPCA jamais deve mascarar um índice projetado como publicado: a marcação fica explícita
     * na linha de `index_rates` e é propagada para a memória de cálculo da curva.
     *
     * Reaproveita o conceito já existente (`source_reference = 'forward_projection'` usado pelo CDI):
     * o `up()` faz backfill de `is_projected = true` nessas linhas para manter a semântica consistente.
     */
    public function up(): void
    {
        Schema::table('index_rates', function (Blueprint $table): void {
            $table->boolean('is_projected')->default(false)->after('source_reference');
            $table->string('projection_source')->nullable()->after('is_projected');
            $table->date('projection_reference_date')->nullable()->after('projection_source');
            $table->string('projection_policy')->nullable()->after('projection_reference_date');
            $table->text('notes')->nullable()->after('projection_policy');
        });

        DB::table('index_rates')
            ->where('source_reference', 'forward_projection')
            ->update(['is_projected' => true]);
    }

    public function down(): void
    {
        Schema::table('index_rates', function (Blueprint $table): void {
            $table->dropColumn([
                'is_projected',
                'projection_source',
                'projection_reference_date',
                'projection_policy',
                'notes',
            ]);
        });
    }
};
