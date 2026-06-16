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
        Schema::create('emission_pu_daily_curves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emission_id')->constrained()->cascadeOnDelete();
            $table->date('curve_date');
            $table->boolean('is_business_day')->default(false);
            $table->decimal('unit_base_value', 24, 16);
            $table->decimal('unit_corrected_value', 24, 16);
            $table->decimal('factor_di', 24, 16);
            $table->decimal('factor_di_accumulated', 24, 16);
            $table->decimal('factor_spread', 24, 16);
            $table->decimal('factor_spread_di', 24, 16);
            $table->decimal('interest_real_unit_value', 24, 16);
            $table->decimal('updated_unit_value', 24, 16);
            $table->decimal('amortization_ratio', 24, 16)->nullable();
            $table->decimal('amortization_unit_value', 24, 16);
            $table->decimal('residual_unit_value', 24, 16);
            $table->decimal('quantity', 20, 4);
            $table->decimal('total_value', 30, 16);
            $table->decimal('interest_payment_unit_value', 24, 16);
            $table->decimal('interest_payment_value', 30, 16);
            $table->decimal('payment_total_unit_value', 24, 16);
            $table->decimal('payment_total_value', 30, 16);
            $table->unsignedSmallInteger('dup_correction')->nullable();
            $table->unsignedSmallInteger('dut_correction')->nullable();
            $table->unsignedSmallInteger('dup_interest')->nullable();
            $table->unsignedSmallInteger('dut_interest')->nullable();
            $table->date('index_rate_date')->nullable();
            $table->decimal('index_rate_value', 12, 8)->nullable();
            $table->date('event_original_date')->nullable();
            $table->date('event_effective_date')->nullable();
            $table->timestamps();

            $table->unique(['emission_id', 'curve_date']);
            $table->index(['emission_id', 'event_effective_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emission_pu_daily_curves');
    }
};
