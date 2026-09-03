<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Histórico append-only do valor comercial de cada unidade.
     *
     * Sem `effective_to`: o valor vigente numa data é a última linha com
     * `effective_from <= data`. Fechar a linha anterior exigiria reescrevê-la, e
     * um histórico financeiro que se reescreve deixa de ser prova do que foi
     * informado.
     *
     * Sem UNIQUE em (unidade, vigência), também deliberadamente: corrigir um
     * valor lançado errado para 01/07 é registrar outra linha para 01/07, não
     * apagar a primeira. O desempate na leitura é o maior `id`.
     *
     * `restrictOnDelete` na unidade acompanha o que `contracts` já faz: uma
     * unidade com história financeira não é removível. A autoria é
     * `nullOnDelete` porque perder o usuário não pode apagar o fato.
     */
    public function up(): void
    {
        Schema::create('construction_unit_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('construction_unit_id')->constrained()->restrictOnDelete();
            $table->decimal('value', 15, 2);
            $table->date('effective_from');
            $table->string('source', 40);
            $table->text('reason')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['construction_unit_id', 'effective_from'], 'construction_unit_values_unit_effective_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_unit_values');
    }
};
