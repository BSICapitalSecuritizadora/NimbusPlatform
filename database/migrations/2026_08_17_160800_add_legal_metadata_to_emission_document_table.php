<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Classifica juridicamente cada documento vinculado a uma emissão (§3 e §35).
 *
 * A classificação vive no vínculo, não em `documents`: a tabela de documentos é
 * compartilhada com o portal do investidor e sua `category` responde a outra
 * pergunta ("onde isto aparece no portal"). O mesmo arquivo pode ainda ser
 * "Aditamento ao Termo" para uma emissão e material de apoio para outra.
 *
 * `amendment_order` ordena a cadeia quando as datas empatam ou faltam — é o
 * "3º Aditamento" do documento, não uma posição calculada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emission_document', function (Blueprint $table): void {
            $table->string('legal_document_type')->nullable()->after('document_id');
            $table->date('document_date')->nullable()->after('legal_document_type');
            $table->date('signed_at')->nullable()->after('document_date');
            $table->unsignedInteger('amendment_order')->nullable()->after('signed_at');
            $table->foreignId('amends_document_id')->nullable()->after('amendment_order')
                ->constrained('documents')->nullOnDelete();
            $table->boolean('is_guarantee_source')->default(false)->after('amends_document_id');

            $table->index(['emission_id', 'legal_document_type'], 'emission_document_legal_type_index');
        });
    }

    public function down(): void
    {
        Schema::table('emission_document', function (Blueprint $table): void {
            $table->dropIndex('emission_document_legal_type_index');
            $table->dropConstrainedForeignId('amends_document_id');
            $table->dropColumn([
                'legal_document_type',
                'document_date',
                'signed_at',
                'amendment_order',
                'is_guarantee_source',
            ]);
        });
    }
};
