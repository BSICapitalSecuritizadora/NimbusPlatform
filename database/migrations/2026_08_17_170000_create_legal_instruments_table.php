<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instrumento jurídico da emissão: CCB, CCI, AFI, AFQ, cessão, conta vinculada,
 * Termo de Securitização (§2 e §23 do escopo).
 *
 * A tabela guarda só a identidade do instrumento. Tudo que é conteúdo — valor,
 * partes, prazos, cobertura — vive em `legal_instrument_fields`, versionado e
 * com proveniência, porque essas informações mudam por aditamento e precisam
 * ser reconstruíveis em qualquer data.
 *
 * Um instrumento não é uma garantia: uma CCB pode constituir várias. A ligação
 * está em `guarantees.legal_instrument_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_instruments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('emission_id')->constrained()->cascadeOnDelete();

            $table->string('type');
            $table->string('number')->nullable();
            $table->string('name');
            $table->string('status')->default('active');

            $table->text('description')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['emission_id', 'type']);
            $table->index(['emission_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_instruments');
    }
};
