<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Série projetada de número-índice (ex.: curva IPCA de mercado/ANBIMA).
     *
     * Uma série agrupa as linhas projetadas de `index_rates` e carrega o ciclo de vida maker/checker
     * (rascunho → importada → aprovada/rejeitada → obsoleta). A curva operacional só pode consumir
     * projeção de uma série APROVADA; a aprovação registra importador (maker) e aprovador (checker).
     */
    public function up(): void
    {
        Schema::create('index_projection_series', function (Blueprint $table): void {
            $table->id();
            $table->string('indexer', 20);
            $table->string('name');
            $table->string('status', 20)->default('draft');
            $table->string('projection_source')->nullable();
            $table->string('projection_policy')->nullable();
            $table->string('version')->nullable();
            $table->date('reference_date')->nullable();
            $table->text('description')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('obsolete_reason')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('obsoleted_at')->nullable();
            $table->timestamps();

            $table->index(['indexer', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('index_projection_series');
    }
};
