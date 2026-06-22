<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emission_pu_daily_curves', function (Blueprint $table) {
            $table->json('calculation_memory')
                ->nullable()
                ->after('event_effective_date');
        });
    }

    public function down(): void
    {
        Schema::table('emission_pu_daily_curves', function (Blueprint $table) {
            $table->dropColumn('calculation_memory');
        });
    }
};
