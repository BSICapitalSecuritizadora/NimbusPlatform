<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * As sete seções em que a construtora responde sobre a competência.
     *
     * Nascem todas juntas, no momento em que a revisão é aberta, e todas como
     * pendentes. Criá-las sob demanda -- conforme a construtora abrisse cada aba
     * -- faria "seção inexistente" e "seção não revisada" virarem o mesmo estado,
     * e a submissão não teria como saber o que ainda falta.
     *
     * `Pending` separado de `Confirmed` é o que impede uma seção nunca aberta de
     * ser lida como concordância silenciosa.
     */
    public function up(): void
    {
        Schema::create('sales_board_builder_review_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_board_builder_review_id')
                ->constrained(indexName: 'builder_sections_review_foreign')
                ->restrictOnDelete();
            $table->string('section', 40);
            $table->string('status', 20);
            $table->text('comment')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['sales_board_builder_review_id', 'section'],
                'builder_sections_review_section_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_board_builder_review_sections');
    }
};
