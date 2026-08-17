<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico de avaliações de uma garantia (§20 e §21 do escopo).
 *
 * O motor escolhe a avaliação vigente na competência analisada pela
 * `valuation_date`, e não a mais recente em termos absolutos: um laudo emitido
 * hoje não pode reescrever a cobertura de um mês já fechado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guarantee_valuations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('guarantee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();

            $table->date('valuation_date');
            $table->decimal('value', 18, 2);
            $table->string('basis')->default('appraisal');

            $table->string('appraiser')->nullable();
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['guarantee_id', 'valuation_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guarantee_valuations');
    }
};
