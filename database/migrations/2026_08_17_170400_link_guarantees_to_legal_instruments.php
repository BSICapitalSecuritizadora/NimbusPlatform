<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liga a garantia ao instrumento que a constitui (§14 do escopo).
 *
 * Nulo continua sendo estado válido: garantias cadastradas manualmente ou
 * extraídas do Termo antes deste módulo não têm instrumento próprio, e inventar
 * um durante a migração criaria uma relação que nenhum documento sustenta (§35).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guarantees', function (Blueprint $table): void {
            $table->foreignId('legal_instrument_id')->nullable()->after('emission_id')
                ->constrained()->nullOnDelete();

            $table->index(['legal_instrument_id']);
        });

        Schema::table('guarantee_document_references', function (Blueprint $table): void {
            // Papel do documento no dossiê da própria garantia (§15): a AFI
            // identificada dentro da CCB pode depois receber o contrato de AFI,
            // a matrícula registrada e o laudo.
            $table->string('document_role')->nullable()->after('reference_type');
        });
    }

    public function down(): void
    {
        Schema::table('guarantee_document_references', function (Blueprint $table): void {
            $table->dropColumn('document_role');
        });

        Schema::table('guarantees', function (Blueprint $table): void {
            $table->dropIndex(['legal_instrument_id']);
            $table->dropConstrainedForeignId('legal_instrument_id');
        });
    }
};
