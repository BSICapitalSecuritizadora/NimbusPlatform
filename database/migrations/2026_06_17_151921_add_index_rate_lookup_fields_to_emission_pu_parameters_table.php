<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emission_pu_parameters', function (Blueprint $table) {
            $table->string('index_rate_lookup_mode', 60)
                ->default('previous_available_business_day')
                ->after('calendar_code');
            $table->unsignedSmallInteger('index_rate_lag_business_days')
                ->default(1)
                ->after('index_rate_lookup_mode');
        });
    }

    public function down(): void
    {
        Schema::table('emission_pu_parameters', function (Blueprint $table) {
            $table->dropColumn([
                'index_rate_lookup_mode',
                'index_rate_lag_business_days',
            ]);
        });
    }
};
