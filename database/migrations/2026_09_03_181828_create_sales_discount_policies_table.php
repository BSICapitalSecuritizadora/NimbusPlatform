<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Desconto comercial máximo autorizado por empreendimento, com vigência.
     *
     * Por empreendimento e não por emissão: é a obra que tem tabela de preço e
     * política de venda. Append-only pelas mesmas razões do histórico de valor
     * das unidades -- a pergunta que este quadro responde é "o que a BSI
     * autorizava naquela data", e reescrever a linha destruiria a resposta.
     *
     * `maximum_discount_percent` é percentual, não fração: 5,00 significa 5%.
     * O intervalo aceito (0 a 100) é validado na aplicação, onde a mensagem de
     * erro pode ser útil, e não num CHECK que o SQLite dos testes trataria de
     * forma diferente do MySQL de produção.
     */
    public function up(): void
    {
        Schema::create('sales_discount_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('construction_id')->constrained()->restrictOnDelete();
            $table->decimal('maximum_discount_percent', 5, 2);
            $table->date('effective_from');
            $table->text('reason')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['construction_id', 'effective_from'], 'sales_discount_policies_construction_effective_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_discount_policies');
    }
};
