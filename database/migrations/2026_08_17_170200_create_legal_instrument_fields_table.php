<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Valores consolidáveis do instrumento — o núcleo do módulo.
 *
 * Cada linha é *uma versão de um campo*, com de onde veio (documento, cláusula,
 * página, trecho), desde quando vale (`effective_date`) e em que situação está
 * (`status`). A tabela é append-only: nada é atualizado no lugar.
 *
 * Uma consequência de desenho vale explicitar, porque é o que faz cinco
 * requisitos do escopo caírem de uma vez: **uma linha `pending_review` é a
 * proposta de alteração**. Confirmar promove a linha a `confirmed` e rebaixa a
 * anterior a `superseded`. Disso saem, sem estrutura adicional:
 *
 * - proveniência por campo (§8), porque a fonte está na própria linha;
 * - posição vigente (§9), que é a última `confirmed` por campo;
 * - aditamento alterando o anterior (§10), que é só uma linha mais recente;
 * - histórico preservado (§11), porque a anterior continua lá;
 * - consulta retroativa (§12), filtrando por `effective_date <= data`;
 * - mesclagem entre documentos (§17), pois campos diferentes convivem;
 * - reprocessamento não destrutivo (§38), já que o novo entra como pendente.
 *
 * `value_numeric` e `value_date` existem para que a comparação entre versões
 * seja exata: "R$ 30.000.000,00" e "30000000" não podem virar uma alteração
 * falsa na tela de revisão.
 *
 * `guarantee_id` permite que o campo pertença a uma garantia filha do
 * instrumento (§14) — a matrícula vigente da AFI é campo da garantia, não da CCB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_instrument_fields', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('legal_instrument_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guarantee_id')->nullable()->constrained()->nullOnDelete();

            $table->string('field_key');
            $table->string('value_type')->default('text');

            $table->text('value')->nullable();
            $table->decimal('value_numeric', 20, 6)->nullable();
            $table->date('value_date')->nullable();

            $table->date('effective_date')->nullable();
            $table->string('status')->default('pending_review');
            $table->string('evidence_level')->default('explicit');
            $table->decimal('confidence_score', 5, 4)->nullable();

            $table->foreignId('legal_instrument_document_id')->nullable()
                ->constrained('legal_instrument_documents')->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('clause')->nullable();
            $table->unsignedInteger('page')->nullable();
            $table->text('excerpt')->nullable();

            $table->foreignId('supersedes_id')->nullable()
                ->constrained('legal_instrument_fields')->nullOnDelete();

            $table->boolean('has_conflict')->default(false);
            $table->text('conflict_reason')->nullable();

            $table->text('review_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index(['legal_instrument_id', 'field_key', 'status'], 'legal_instrument_field_lookup_index');
            $table->index(['legal_instrument_id', 'status'], 'legal_instrument_field_status_index');
            $table->index(['guarantee_id', 'field_key'], 'legal_instrument_field_guarantee_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_instrument_fields');
    }
};
