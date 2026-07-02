<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emission_pu_parameters', function (Blueprint $table) {
            $table->smallInteger('index_rate_lag_business_days')
                ->default(1)
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('emission_pu_parameters', function (Blueprint $table) {
            $table->unsignedSmallInteger('index_rate_lag_business_days')
                ->default(1)
                ->change();
        });
    }
};
