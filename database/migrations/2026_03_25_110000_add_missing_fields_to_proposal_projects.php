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
        Schema::table('proposal_projects', function (Blueprint $table) {
            $table->date('sales_launch_date')->nullable()->after('launch_date')->comment('Lançamento das Vendas');
            $table->date('construction_start_date')->nullable()->after('sales_launch_date')->comment('Início das Obras');
            $table->date('delivery_forecast_date')->nullable()->after('construction_start_date')->comment('Previsão de Entrega');
            $table->integer('remaining_months')->default(0)->after('delivery_forecast_date')->comment('Prazo Remanescente (meses)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('proposal_projects', function (Blueprint $table) {
            $table->dropColumn(['sales_launch_date', 'construction_start_date', 'delivery_forecast_date', 'remaining_months']);
        });
    }
};
