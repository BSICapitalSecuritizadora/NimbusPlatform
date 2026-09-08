<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O que aconteceu na competência, congelado.
     *
     * Sem esta tabela o snapshot mentiria por omissão. Um contrato vendido em
     * 05/07 e distratado em 20/07 deixa a unidade em estoque no dia 31 -- e uma
     * versão que guardasse só a posição final registraria um mês em que nada
     * aconteceu, quando aconteceram duas coisas relevantes. A construtora que
     * for conferir a competência precisa ver exatamente os movimentos que o
     * Nimbus considerou.
     *
     * Uma tabela só para os três tipos porque todos têm a mesma âncora --
     * contrato e unidade -- e as diferenças são poucas colunas anuláveis. Três
     * tabelas quase idênticas fariam toda leitura de "o que aconteceu no mês"
     * virar um `UNION` e toda coluna nova nascer três vezes.
     *
     * `event_date` fica nulo na quitação, e isso é uma afirmação, não uma
     * lacuna: o motor prova que o contrato não estava quitado antes do mês e
     * estava no fechamento, mas não apura o dia exato. Preencher com a data de
     * algum pagamento seria inventar uma regra de negócio nova para não deixar
     * uma coluna vazia.
     *
     * Comprador não entra. O Quadro é sobre unidades, contratos e valores; nome,
     * telefone e e-mail do cliente não participam de nenhum número apurado, e
     * copiá-los para um snapshot imutável espalharia dado pessoal por um lugar
     * de onde ele nunca mais sairia.
     */
    public function up(): void
    {
        Schema::create('sales_board_cycle_movements', function (Blueprint $table) {
            $table->id();
            /**
             * Nome explícito porque o convencionado --
             * `sales_board_cycle_movements_sales_board_cycle_baseline_id_foreign`
             * -- tem 65 caracteres e estoura o limite de 64 do MySQL. O SQLite
             * aceitaria em silêncio, e a migration só quebraria em produção.
             */
            $table->foreignId('sales_board_cycle_baseline_id')
                ->constrained(indexName: 'sales_board_cycle_movements_baseline_foreign')
                ->restrictOnDelete();
            $table->string('movement_type', 20);

            $table->foreignId('construction_unit_id')->constrained()->restrictOnDelete();
            $table->string('block', 50)->nullable();
            $table->string('unit', 50)->nullable();

            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->string('contract_code')->nullable();

            $table->date('event_date')->nullable();
            $table->date('sale_date')->nullable();
            $table->decimal('sale_value', 15, 2)->nullable();
            $table->date('cancellation_date')->nullable();
            $table->unsignedInteger('settlement_installments_total')->nullable();

            $table->decimal('unit_reference_value', 15, 2)->nullable();
            $table->string('unit_reference_value_source', 20)->nullable();
            $table->date('unit_reference_value_effective_from')->nullable();
            $table->foreignId('sales_discount_policy_id')->nullable()->constrained()->restrictOnDelete();
            $table->integer('authorized_discount_basis_points')->nullable();
            $table->decimal('minimum_authorized_value', 15, 2)->nullable();
            $table->integer('effective_discount_basis_points')->nullable();
            $table->decimal('difference_value', 15, 2)->nullable();
            $table->string('conformity_status', 20)->nullable();
            $table->text('conformity_reason')->nullable();

            $table->char('source_fingerprint', 64);
            $table->char('snapshot_fingerprint', 64);

            $table->timestamps();

            /**
             * Um contrato produz no máximo uma venda, um distrato e uma
             * transição de quitação por competência: a venda é a própria
             * `sale_date` do contrato, o distrato a sua `cancellation_date`, e a
             * quitação é uma transição de estado entre dois instantes. Uma
             * revenda no mesmo mês é outro contrato, não um segundo movimento
             * deste.
             */
            $table->unique(
                ['sales_board_cycle_baseline_id', 'movement_type', 'contract_id'],
                'sales_board_cycle_movements_baseline_type_contract_unique',
            );
            $table->index(
                ['sales_board_cycle_baseline_id', 'movement_type'],
                'sales_board_cycle_movements_baseline_type_index',
            );
            $table->index('contract_id', 'sales_board_cycle_movements_contract_index');
            $table->index('construction_unit_id', 'sales_board_cycle_movements_unit_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_board_cycle_movements');
    }
};
