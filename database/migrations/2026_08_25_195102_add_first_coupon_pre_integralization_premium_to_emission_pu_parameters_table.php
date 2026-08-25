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
        Schema::table('emission_pu_parameters', function (Blueprint $table) {
            $table->boolean('first_coupon_pre_integralization_premium_enabled')
                ->default(false)
                ->after('index_rate_lag_business_days');
            $table->unsignedSmallInteger('first_coupon_pre_integralization_business_days')
                ->nullable()
                ->after('first_coupon_pre_integralization_premium_enabled');
            $table->boolean('first_coupon_pre_integralization_apply_index_factor')
                ->default(true)
                ->after('first_coupon_pre_integralization_business_days');
            $table->boolean('first_coupon_pre_integralization_apply_spread_factor')
                ->default(true)
                ->after('first_coupon_pre_integralization_apply_index_factor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('emission_pu_parameters', function (Blueprint $table) {
            $table->dropColumn([
                'first_coupon_pre_integralization_premium_enabled',
                'first_coupon_pre_integralization_business_days',
                'first_coupon_pre_integralization_apply_index_factor',
                'first_coupon_pre_integralization_apply_spread_factor',
            ]);
        });
    }
};
