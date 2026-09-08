<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O ciclo mensal do Quadro de Vendas de um empreendimento.
     *
     * Um ciclo é `empreendimento + competência`, nunca `emissão + competência`.
     * A emissão é o vínculo, e a prontidão dos dados é por obra: numa emissão
     * com três empreendimentos, dois podem ter fonte completa e o terceiro não.
     * Um ciclo único da emissão obrigaria a esperar o pior deles ou a gerar uma
     * posição que o Nimbus não consegue explicar inteira.
     *
     * `sales_boards` continua sendo a posição publicada/aprovada, e esta tabela
     * não a substitui nem escreve nela: aqui vive o que foi *apurado* numa data,
     * com todas as versões pelas quais passou. Publicar é decisão de outra fase.
     *
     * `position_date` é persistida ao lado de `reference_month` em vez de
     * derivada na leitura. São duas afirmações diferentes -- a competência e o
     * dia exato em que a posição foi tirada -- e um snapshot que precisasse
     * recalcular a segunda dependeria de a regra nunca mudar.
     *
     * FKs RESTRICT: um snapshot financeiro não pode evaporar porque alguém
     * apagou o empreendimento. A autoria é `nullOnDelete`, porque perder o
     * usuário não pode apagar o fato.
     *
     * `current_baseline_id` não nasce aqui: a tabela de baselines ainda não
     * existe neste ponto da ordem das migrations, e criar a coluna sem a FK
     * seria um ponteiro sem garantia. Ela é adicionada, já com a FK, depois que
     * as duas tabelas existem.
     */
    public function up(): void
    {
        Schema::create('sales_board_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emission_id')->constrained()->restrictOnDelete();
            $table->foreignId('construction_id')->constrained()->restrictOnDelete();
            $table->date('reference_month');
            $table->date('position_date');
            $table->string('status', 30);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /**
             * A idempotência da geração é garantida aqui, não no serviço: duas
             * requisições simultâneas para a mesma competência disputam esta
             * unique, e a perdedora relê o ciclo que a vencedora criou.
             */
            $table->unique(['construction_id', 'reference_month'], 'sales_board_cycles_construction_month_unique');
            $table->index(['emission_id', 'reference_month'], 'sales_board_cycles_emission_month_index');
            $table->index('status', 'sales_board_cycles_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_board_cycles');
    }
};
