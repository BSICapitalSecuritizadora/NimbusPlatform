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
        Schema::create('emission_pu_parameters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emission_id')->constrained()->cascadeOnDelete();
            $table->date('curve_start_date');
            $table->date('curve_end_date');
            $table->decimal('initial_unit_value', 24, 16);
            $table->decimal('spread_rate', 12, 8);
            $table->string('indexer', 20)->default('CDI');
            $table->unsignedSmallInteger('business_day_basis')->default(252);
            $table->string('calendar_code', 20)->default('B3');
            $table->boolean('legacy_projection_enabled')->default(true);
            $table->timestamps();

            $table->unique('emission_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emission_pu_parameters');
    }
};
