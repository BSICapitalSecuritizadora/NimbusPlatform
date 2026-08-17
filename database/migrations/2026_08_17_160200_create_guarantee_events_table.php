<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico jurídico da garantia (§8 do escopo).
 *
 * A tabela é append-only por construção: nada aqui é atualizado, só inserido.
 * É o que permite reconstruir a posição da garantia em qualquer data — e é por
 * isso que `effective_date` (quando o efeito jurídico começa) é separada de
 * `created_at` (quando o sistema soube).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guarantee_events', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('guarantee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guarantee_document_reference_id')->nullable()
                ->constrained('guarantee_document_references')->nullOnDelete();

            $table->string('event_type');
            $table->date('effective_date')->nullable();
            $table->string('title');
            $table->text('description')->nullable();

            $table->json('previous_values')->nullable();
            $table->json('new_values')->nullable();

            $table->string('source')->default('manual');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['guarantee_id', 'effective_date']);
            $table->index(['guarantee_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guarantee_events');
    }
};
