<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rastreabilidade documental de cada garantia (§6 do escopo): de qual documento,
 * cláusula e página veio a informação, com que confiança e quem confirmou.
 *
 * Os metadados do documento são copiados (título, tipo, data) em vez de lidos
 * sempre por join: o vínculo precisa sobreviver à exclusão do documento no
 * acervo, senão a garantia perde a origem que a justifica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guarantee_document_references', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('guarantee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reference_type')->default('constitution');

            $table->string('document_title')->nullable();
            $table->string('document_name')->nullable();
            $table->string('document_type')->nullable();
            $table->date('document_date')->nullable();
            $table->date('signed_at')->nullable();
            $table->string('version')->nullable();

            $table->unsignedInteger('page')->nullable();
            $table->string('clause')->nullable();
            $table->string('item')->nullable();
            $table->text('excerpt')->nullable();

            $table->string('confidence_level')->nullable();
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->string('extraction_method')->nullable();
            $table->timestamp('extracted_at')->nullable();

            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();

            $table->index(['guarantee_id', 'reference_type']);
            $table->index(['guarantee_id', 'document_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guarantee_document_references');
    }
};
