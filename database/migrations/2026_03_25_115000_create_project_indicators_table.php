<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_indicators')) {
            $fkExists = collect(DB::select(
                "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = 'project_indicators'
                 AND CONSTRAINT_TYPE = 'FOREIGN KEY'
                 LIMIT 1"
            ))->isNotEmpty();

            if (! $fkExists) {
                Schema::table('project_indicators', function (Blueprint $table): void {
                    $table->foreign('project_id')->references('id')->on('proposal_projects')->cascadeOnDelete();
                });
            }

            return;
        }

        Schema::create('project_indicators', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('proposal_projects')->cascadeOnDelete();

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
