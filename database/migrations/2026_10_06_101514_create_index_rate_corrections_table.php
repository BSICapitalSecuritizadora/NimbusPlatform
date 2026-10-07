<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Livro de correções de observações de índice já registradas.
     *
     * Corrigir uma taxa histórica é ato de governança, não sincronização: cada
     * correção guarda o valor e a origem anteriores, o novo valor e a nova origem,
     * quem corrigiu, por quê e quais versões de curva usavam a observação. A linha
     * em `index_rates` passa a valer o novo valor; o anterior só sobrevive aqui.
     * Tabela nova e vazia: nada é migrado nem recalculado.
     */
    public function up(): void
    {
        Schema::create('index_rate_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('index_rate_id')->nullable()->constrained('index_rates')->nullOnDelete();
            $table->string('indexer', 20);
            $table->date('rate_date');
            $table->string('origin', 32);
            $table->decimal('previous_rate_value', 20, 8);
            $table->decimal('new_rate_value', 20, 8);
            $table->string('previous_source')->nullable();
            $table->string('new_source')->nullable();
            $table->string('previous_source_reference')->nullable();
            $table->string('new_source_reference')->nullable();
            $table->text('reason');
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('affected_curve_versions')->nullable();
            $table->timestamp('corrected_at');
            $table->timestamps();

            $table->index(['indexer', 'rate_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('index_rate_corrections');
    }
};
