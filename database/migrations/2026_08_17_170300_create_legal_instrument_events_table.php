<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Narrativa jurídica do instrumento (§13 do escopo).
 *
 * `legal_instrument_fields` responde "qual é o valor vigente e desde quando".
 * Esta tabela responde "o que aconteceu": um aditamento vira um evento com
 * título legível e o conjunto de campos que ele alterou, que é o que o
 * histórico (§11) e a linha do tempo da emissão (§29) exibem.
 *
 * O evento nasce da confirmação de uma alteração — nunca da extração sozinha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_instrument_events', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('legal_instrument_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_instrument_document_id')->nullable()
                ->constrained('legal_instrument_documents')->nullOnDelete();
            $table->foreignId('guarantee_id')->nullable()->constrained()->nullOnDelete();

            $table->string('event_type');
            $table->date('effective_date')->nullable();
            $table->string('title');
            $table->text('description')->nullable();

            $table->json('change_set')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['legal_instrument_id', 'effective_date'], 'legal_instrument_event_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_instrument_events');
    }
};
