<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Libera o versionamento por campo para garantias sem instrumento jurídico.
 *
 * `legal_instrument_fields` já resolve, por desenho, tudo o que a consolidação
 * de garantias precisa — proveniência por campo, valor vigente, valor anterior
 * e desde quando cada um vale. O que impedia o reuso era a exigência de um
 * instrumento: uma garantia identificada num documento avulso, sem CCB nem
 * contrato cadastrado, não tinha onde pendurar a versão do campo e ficava sem
 * histórico de "vigente até / vigente desde".
 *
 * Tornar a coluna anulável é o menor caminho para que a mesma tabela sirva às
 * duas origens. A alternativa seria uma segunda tabela de versões só para
 * garantias — um fluxo paralelo de consolidação, exatamente o que não se quer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_instrument_fields', function (Blueprint $table): void {
            $table->foreignId('legal_instrument_id')->nullable()->change();

            $table->index(['guarantee_id', 'field_key', 'status'], 'legal_instrument_field_guarantee_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('legal_instrument_fields', function (Blueprint $table): void {
            $table->dropIndex('legal_instrument_field_guarantee_status_index');

            $table->foreignId('legal_instrument_id')->nullable(false)->change();
        });
    }
};
