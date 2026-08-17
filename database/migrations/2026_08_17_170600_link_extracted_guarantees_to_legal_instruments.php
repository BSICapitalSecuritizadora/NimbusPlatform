<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liga uma garantia detectada ao instrumento cujo documento a revelou.
 *
 * Sem isso, a garantia extraída de uma CCB nasceria solta na emissão e alguém
 * teria de reencontrar o instrumento à mão depois de confirmar. Com o vínculo,
 * a confirmação já entrega a garantia pendurada na CCB (§14 do escopo).
 *
 * O fluxo de revisão continua sendo o de `extracted_guarantees` — não há um
 * segundo caminho de aprovação para garantias vindas de instrumento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extracted_guarantees', function (Blueprint $table): void {
            $table->foreignId('legal_instrument_id')->nullable()->after('emission_id')
                ->constrained()->nullOnDelete();

            $table->foreignId('legal_instrument_document_id')->nullable()->after('legal_instrument_id')
                ->constrained('legal_instrument_documents')->nullOnDelete();

            $table->index(['legal_instrument_id', 'status'], 'extracted_guarantee_instrument_index');
        });
    }

    public function down(): void
    {
        Schema::table('extracted_guarantees', function (Blueprint $table): void {
            $table->dropIndex('extracted_guarantee_instrument_index');
            $table->dropConstrainedForeignId('legal_instrument_document_id');
            $table->dropConstrainedForeignId('legal_instrument_id');
        });
    }
};
