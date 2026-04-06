<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('proposal_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->constrained('proposals')->onDelete('cascade');
            $table->string('name')->comment('Nome do Empreendimento');
            $table->string('company_name')->nullable()->comment('Razão Social (SPE)');
            $table->string('site')->nullable();

            // Financial Data
            $table->decimal('value_requested', 15, 2)->default(0);
            $table->decimal('land_market_value', 15, 2)->default(0);
            $table->decimal('land_area', 10, 2)->default(0);

            // Address
            $table->string('cep', 9)->nullable();
            $table->string('logradouro')->nullable();
            $table->string('numero', 50)->nullable();
            $table->string('complemento')->nullable();
            $table->string('bairro')->nullable();
            $table->string('cidade')->nullable();
            $table->string('estado', 2)->nullable();

            // Timeline & Units
            $table->date('launch_date')->nullable();
            $table->integer('units_exchanged')->default(0)->comment('Unidades Permutadas');
            $table->integer('units_paid')->default(0)->comment('Unidades Quitadas');
            $table->integer('units_unpaid')->default(0)->comment('Unidades Não Quitadas');
            $table->integer('units_stock')->default(0)->comment('Unidades em Estoque');
            $table->integer('units_total')->default(0)->comment('Total de Unidades');
            $table->decimal('sales_percentage', 5, 2)->default(0);

            // Costs
            $table->decimal('cost_incurred', 15, 2)->default(0)->comment('Custo Incidido');
            $table->decimal('cost_to_incur', 15, 2)->default(0)->comment('Custo a Incorrer');
            $table->decimal('cost_total', 15, 2)->default(0)->comment('Custo Total');
            $table->decimal('work_stage_percentage', 5, 2)->default(0)->comment('Estágio da Obra %');

            // Values
            $table->decimal('value_paid', 15, 2)->default(0)->comment('Valor das Unidades Quitadas');
            $table->decimal('value_unpaid', 15, 2)->default(0)->comment('Valor das Unidades Não Quitadas');
            $table->decimal('value_stock', 15, 2)->default(0)->comment('Valor das Unidades em Estoque');
            $table->decimal('value_total_sale', 15, 2)->default(0)->comment('Valor Total de Venda (VGV)');
            $table->decimal('value_received', 15, 2)->default(0)->comment('Valor já Recebido');
            $table->decimal('value_until_keys', 15, 2)->default(0)->comment('Valor a receber até as chaves');
            $table->decimal('value_post_keys', 15, 2)->default(0)->comment('Valor a receber pós chaves');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proposal_projects');
    }
};
