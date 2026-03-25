<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_indicators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('proposal_projects')->cascadeOnDelete();
            
            // Pairs of Ideal and Limit thresholds
            $table->decimal('financiamento_custo_obra_ideal', 10, 2)->nullable();
            $table->decimal('financiamento_custo_obra_limite', 10, 2)->nullable();
            
            $table->decimal('financiamento_vgv_ideal', 10, 2)->nullable();
            $table->decimal('financiamento_vgv_limite', 10, 2)->nullable();
            
            $table->decimal('custo_obra_vgv_ideal', 10, 2)->nullable();
            $table->decimal('custo_obra_vgv_limite', 10, 2)->nullable();
            
            $table->decimal('recebiveis_vfcto_ideal', 10, 2)->nullable();
            $table->decimal('recebiveis_vfcto_limite', 10, 2)->nullable();
            
            $table->decimal('recebiveis_terreno_vfcto_ideal', 10, 2)->nullable();
            $table->decimal('recebiveis_terreno_vfcto_limite', 10, 2)->nullable();
            
            $table->decimal('vendas_liquido_permutas_ideal', 10, 2)->nullable();
            $table->decimal('vendas_liquido_permutas_limite', 10, 2)->nullable();
            
            $table->decimal('terreno_vgv_ideal', 10, 2)->nullable();
            $table->decimal('terreno_vgv_limite', 10, 2)->nullable();
            
            $table->decimal('terreno_custo_obra_ideal', 10, 2)->nullable();
            $table->decimal('terreno_custo_obra_limite', 10, 2)->nullable();
            
            $table->decimal('ltv_ideal', 10, 2)->nullable();
            $table->decimal('ltv_limite', 10, 2)->nullable();
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_indicators');
    }
};
