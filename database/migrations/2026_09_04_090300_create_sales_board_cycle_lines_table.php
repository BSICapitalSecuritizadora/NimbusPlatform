<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uma unidade, como ela era na data da posição, congelada.
     *
     * Uma linha por `ConstructionUnit` do baseline, sem exceção -- os totais da
     * versão são a soma destas linhas, e uma unidade que não estivesse aqui
     * seria um número que não se consegue abrir.
     *
     * Além da FK, a identificação é copiada. Uma FK responde "qual unidade é
     * esta hoje"; o snapshot precisa responder "qual unidade era esta quando a
     * construtora conferiu", e um bloco renomeado depois não pode reescrever o
     * que foi validado. O mesmo vale para código, data e valor do contrato: se
     * amanhã `contracts.sale_value` mudar, esta linha continua com o valor que
     * formou o número congelado, e a divergência vira mudança detectável em vez
     * de história reescrita em silêncio.
     *
     * O valor de referência da unidade é congelado em toda linha, não só nas de
     * estoque. Ele custa uma coluna e é o que permite explicar uma venda fora da
     * política sem reabrir a tabela viva -- que é justamente a que pode ter
     * mudado. Continua anulável porque em `Undetermined` pode não haver resposta.
     *
     * Da quitação ficam o estado e a contagem de parcelas, não o cronograma:
     * queremos a memória da *decisão* ("quitado, 12 de 12"), não uma segunda
     * cópia de mil e oitocentas parcelas que já vivem em `contract_installments`
     * e que ninguém reconciliaria depois.
     *
     * Os dois fingerprints por linha são o que transforma "algo mudou" em "a
     * unidade 101 do bloco 01 mudou". Sem eles o hash global do baseline diria
     * apenas que existe diferença, e localizar exigiria comparar tudo com tudo.
     */
    public function up(): void
    {
        Schema::create('sales_board_cycle_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_board_cycle_baseline_id')->constrained()->restrictOnDelete();
            $table->foreignId('construction_unit_id')->constrained()->restrictOnDelete();

            $table->string('block', 50)->nullable();
            $table->string('unit', 50)->nullable();
            $table->string('classification', 20);

            $table->foreignId('contract_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('contract_code')->nullable();
            $table->date('contract_sale_date')->nullable();
            $table->decimal('contract_sale_value', 15, 2)->nullable();

            $table->decimal('unit_reference_value', 15, 2)->nullable();
            $table->string('unit_reference_value_source', 20)->nullable();
            $table->date('unit_reference_value_effective_from')->nullable();

            $table->string('settlement_state', 20)->nullable();
            $table->unsignedInteger('settlement_installments_total')->nullable();
            $table->unsignedInteger('settlement_installments_paid')->nullable();

            $table->foreignId('construction_unit_exchange_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('exchange_value', 15, 2)->nullable();
            $table->date('exchange_effective_from')->nullable();
            $table->date('exchange_ended_on')->nullable();
            $table->string('exchange_kind', 30)->nullable();

            $table->char('source_fingerprint', 64);
            $table->char('snapshot_fingerprint', 64);

            $table->timestamps();

            /**
             * Torna impossível persistir duas classificações para a mesma
             * unidade na mesma versão -- a invariante que faz os baldes fecharem
             * virar propriedade do banco em vez de esperança do código.
             */
            $table->unique(
                ['sales_board_cycle_baseline_id', 'construction_unit_id'],
                'sales_board_cycle_lines_baseline_unit_unique',
            );
            $table->index(
                ['sales_board_cycle_baseline_id', 'classification'],
                'sales_board_cycle_lines_baseline_classification_index',
            );
            $table->index('contract_id', 'sales_board_cycle_lines_contract_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_board_cycle_lines');
    }
};
