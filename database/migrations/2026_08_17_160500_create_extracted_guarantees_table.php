<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Garantias detectadas em documentos, ainda não oficiais (§4 e §5 do escopo).
 *
 * Espelha `extracted_obligations`, que já resolve o mesmo problema para
 * obrigações: a IA produz uma proposta de cadastro, e só a revisão humana a
 * transforma em garantia da emissão.
 *
 * `event_type` e `related_guarantee_id` são o que permite a um aditamento
 * propor "alterar a cobertura mínima da garantia X" em vez de criar uma segunda
 * garantia duplicada. `field_evidence` guarda, por campo, se o dado foi
 * explícito, inferido ou não localizado (§36) — sem isso não há como destacar
 * na revisão o que a IA deduziu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extracted_guarantees', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('emission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('guarantee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('related_guarantee_id')->nullable()
                ->constrained('guarantees')->nullOnDelete();

            $table->string('status')->default('suggested');
            $table->string('event_type')->default('constitution');

            $table->string('type')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('identification')->nullable();

            $table->decimal('contracted_value', 18, 2)->nullable();
            $table->decimal('documentary_value', 18, 2)->nullable();

            $table->string('requirement_basis')->nullable();
            $table->decimal('requirement_value', 18, 2)->nullable();
            $table->decimal('requirement_percentage', 12, 6)->nullable();
            $table->string('requirement_base')->nullable();
            $table->decimal('requirement_multiplier', 12, 4)->nullable();
            $table->text('requirement_formula')->nullable();
            $table->text('requirement_conditions')->nullable();

            $table->string('legal_status')->nullable();
            $table->date('validity_start_date')->nullable();
            $table->date('validity_end_date')->nullable();
            $table->date('effective_date')->nullable();
            $table->string('evaluation_frequency')->nullable();

            $table->string('document_type')->nullable();
            $table->date('document_date')->nullable();
            $table->string('source_clause')->nullable();
            $table->unsignedInteger('source_page')->nullable();
            $table->text('source_excerpt')->nullable();

            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->json('field_evidence')->nullable();
            $table->json('field_confidences')->nullable();

            $table->boolean('has_conflict')->default(false);
            $table->text('conflict_reason')->nullable();

            $table->text('review_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index(['emission_id', 'status']);
            $table->index(['emission_id', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extracted_guarantees');
    }
};
