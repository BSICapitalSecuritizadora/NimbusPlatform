<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permuta de uma unidade, com vigência própria.
     *
     * A permuta é da UNIDADE, não do contrato. `ContractStatus::Exchanged` diz
     * apenas o que vale hoje: não sabe desde quando, não sabe até quando, e some
     * quando o contrato é distratado -- é um estado, não um fato datado. Derivar
     * "quantas unidades estavam permutadas em 07/2026" a partir dele produziria
     * a resposta de hoje para todas as competências.
     *
     * `contract_id` é anulável porque a permuta inicial costuma ser conhecida
     * antes de o contrato existir: a operação declara o baseline enquanto a
     * emissão ainda está em elaboração.
     *
     * `exchange_value` é próprio e não deriva de `contracts.sale_value`. Uma
     * permuta é troca, não venda; o valor atribuído a ela é decisão comercial
     * registrada aqui.
     *
     * Vigência semiaberta `[effective_from, ended_on)`, a mesma filosofia de
     * `ContractOccupancyPeriod`: a permuta que termina no dia D não vale em D.
     *
     * FKs RESTRICT na unidade e no contrato: apagar qualquer um dos dois
     * destruiria a explicação de um número já publicado.
     */
    public function up(): void
    {
        Schema::create('construction_unit_exchanges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('construction_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('exchange_value', 15, 2);
            $table->date('effective_from');
            $table->date('ended_on')->nullable();
            $table->string('kind', 30);
            $table->text('reason')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(
                ['construction_unit_id', 'effective_from'],
                'construction_unit_exchanges_unit_effective_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_unit_exchanges');
    }
};
