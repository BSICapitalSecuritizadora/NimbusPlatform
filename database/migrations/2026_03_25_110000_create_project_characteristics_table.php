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
        Schema::create('project_characteristics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('proposal_projects')->onDelete('cascade');
            $table->integer('blocks')->default(0)->comment('Quantidade de Blocos');
            $table->integer('floors')->default(0)->comment('Quantidade de Pavimentos');
            $table->integer('typical_floors')->default(0)->comment('Quantidade de Andares Tipo');
            $table->integer('units_per_floor')->default(0)->comment('Unidades por Andar');
            $table->integer('total_units')->default(0)->comment('Total de Unidades');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_characteristics');
    }
};
