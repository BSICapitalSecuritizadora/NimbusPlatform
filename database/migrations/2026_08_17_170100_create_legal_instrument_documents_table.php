<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dossiê documental do instrumento (§3 do escopo): documento original,
 * aditamentos e demais instrumentos relacionados.
 *
 * É um vínculo, não uma cópia: o arquivo continua em `documents`, sujeito às
 * mesmas policies, ao mesmo storage privado e à mesma varredura de malware
 * (§39). O que esta tabela acrescenta é o papel do documento na cadeia, a ordem
 * dentro dela e o estado do processamento.
 *
 * `sequence` é o "3º" de "3º Aditamento" — declarado, não calculado: documentos
 * chegam fora de ordem e a numeração contratual é a que vale para desempatar
 * quando duas peças têm a mesma data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_instrument_documents', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('legal_instrument_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();

            $table->string('role')->default('original');
            $table->unsignedInteger('sequence')->nullable();

            $table->date('document_date')->nullable();
            $table->date('signed_at')->nullable();
            $table->string('effect_summary')->nullable();

            $table->string('processing_status')->default('pending');
            $table->string('current_step')->nullable();
            $table->text('message')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('extraction_attempts')->default(0);
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Índices nomeados explicitamente: o nome automático do Laravel
            // (tabela + colunas + sufixo) passa dos 64 caracteres que o MySQL
            // aceita para identificadores, e o erro só aparece fora do SQLite.
            $table->unique(['legal_instrument_id', 'document_id'], 'legal_instrument_document_unique');
            $table->index(['legal_instrument_id', 'document_date'], 'legal_instrument_doc_date_index');
            $table->index(['legal_instrument_id', 'processing_status'], 'legal_instrument_doc_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_instrument_documents');
    }
};
