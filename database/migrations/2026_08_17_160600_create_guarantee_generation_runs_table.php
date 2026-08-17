<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado das extrações documentais de garantias (§43 do escopo).
 *
 * Espelha `obligation_generation_runs`: a interface acompanha "aguardando /
 * processando / concluído / falhou" sem bloquear, e a falha permite retry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guarantee_generation_runs', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('emission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status')->default('pending');
            $table->string('current_step')->nullable();
            $table->text('message')->nullable();
            $table->unsignedInteger('detected_count')->default(0);
            $table->unsignedInteger('conflict_count')->default(0);
            $table->boolean('is_reprocessing')->default(false);
            $table->text('error_message')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            $table->index(['emission_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guarantee_generation_runs');
    }
};
