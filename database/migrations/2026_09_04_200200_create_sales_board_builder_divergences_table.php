<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O que a construtora declarou que não confere.
     *
     * Declaração, não correção. O snapshot continua exatamente como estava: se a
     * construtora diz que a unidade deveria estar em estoque, a linha congelada
     * segue dizendo "financiado" e esta tabela passa a dizer "a construtora
     * afirma estoque". A Gestão recebe as duas versões do fato e decide na fase
     * seguinte -- e é justamente por isso que nada aqui se chama "não
     * conformidade" ainda: uma declaração vira não conformidade depois de
     * analisada, não antes.
     *
     * A âncora é a própria linha ou o próprio movimento congelado, que são
     * imutáveis. Não se copia o valor que o sistema apresentava: ele já está lá,
     * do outro lado da FK, e duplicá-lo criaria duas versões do mesmo número
     * para divergirem com o tempo.
     *
     * Os campos `declared_*` guardam o que a construtora afirma. São anuláveis
     * porque cada tipo exige um conjunto diferente -- valor divergente precisa de
     * valor, data divergente precisa de data -- e a obrigatoriedade vive no tipo,
     * não numa coluna que seria `NOT NULL` para uns e mentira para outros.
     *
     * Venda ausente é o caso sem âncora: o fato afirmado não está no snapshot.
     * Por isso bloco, unidade e contrato podem vir como texto, inclusive de uma
     * unidade que o Nimbus não conhece. Nenhum `ConstructionUnit` ou `Contract` é
     * criado a partir daqui -- criar cadastro a partir de uma alegação ainda não
     * analisada é exatamente o que esta arquitetura evita.
     */
    public function up(): void
    {
        Schema::create('sales_board_builder_divergences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_board_builder_review_id')
                ->constrained(indexName: 'builder_divergences_review_foreign')
                ->restrictOnDelete();
            $table->foreignId('sales_board_builder_review_section_id')
                ->constrained(
                    table: 'sales_board_builder_review_sections',
                    indexName: 'builder_divergences_section_foreign',
                )
                ->restrictOnDelete();
            $table->string('type', 40);

            $table->foreignId('sales_board_cycle_line_id')->nullable()
                ->constrained(indexName: 'builder_divergences_line_foreign')
                ->restrictOnDelete();
            $table->foreignId('sales_board_cycle_movement_id')->nullable()
                ->constrained(indexName: 'builder_divergences_movement_foreign')
                ->restrictOnDelete();
            $table->foreignId('construction_unit_id')->nullable()
                ->constrained(indexName: 'builder_divergences_unit_foreign')
                ->restrictOnDelete();
            $table->foreignId('contract_id')->nullable()
                ->constrained(indexName: 'builder_divergences_contract_foreign')
                ->restrictOnDelete();

            $table->string('declared_block', 50)->nullable();
            $table->string('declared_unit', 50)->nullable();
            $table->string('declared_contract_code', 100)->nullable();
            $table->decimal('declared_value', 15, 2)->nullable();
            $table->date('declared_date')->nullable();
            $table->string('declared_classification', 20)->nullable();

            $table->text('reason');

            $table->timestamps();

            /**
             * A lista de divergências é sempre lida por revisão e agrupada por
             * tipo; esse é o único acesso que precisa de índice próprio. Seção,
             * linha, movimento, contrato e unidade já entram pelos índices que o
             * InnoDB cria para as respectivas chaves estrangeiras.
             */
            $table->index(['sales_board_builder_review_id', 'type'], 'builder_divergences_review_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_board_builder_divergences');
    }
};
