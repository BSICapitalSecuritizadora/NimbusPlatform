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
        Schema::create('measurement_plan_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_set_id')->constrained('measurement_plan_sets')->cascadeOnDelete();
            $table->foreignId('operation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence_number');
            $table->decimal('planned_monthly_percent', 8, 2)->default(0);
            $table->decimal('planned_cumulative_percent', 8, 2)->default(0);
            $table->decimal('initial_realized_cumulative_percent', 8, 2)->default(0);
            $table->decimal('realized_monthly_percent', 8, 2)->default(0);
            $table->decimal('realized_cumulative_percent', 8, 2)->default(0);
            $table->decimal('evolution_diff_percent', 9, 2)->default(0);
            $table->string('evolution_trend', 20)->nullable();
            $table->date('measurement_date')->nullable();
            $table->foreignId('measurement_id')->nullable()->constrained('measurements')->nullOnDelete();
            $table->timestamps();

            $table->unique(['plan_set_id', 'sequence_number']);
            $table->index('operation_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('measurement_plan_lines');
    }
};
