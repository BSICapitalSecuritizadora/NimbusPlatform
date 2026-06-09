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
        Schema::table('emissions', function (Blueprint $table) {
            $table->string('target_audience')->nullable()->after('segment');
            $table->string('distributor')->nullable()->after('registrar');
            $table->string('fiduciary_assignment')->nullable()->after('works_fund');
            $table->string('vehicle_fiduciary_alienation')->nullable()->after('fiduciary_assignment');
            $table->string('quota_fiduciary_alienation')->nullable()->after('vehicle_fiduciary_alienation');
            $table->string('surety')->nullable()->after('quota_fiduciary_alienation');
            $table->string('real_estate_guarantee')->nullable()->after('surety');
            $table->string('property_fiduciary_alienation')->nullable()->after('real_estate_guarantee');
            $table->string('aval')->nullable()->after('property_fiduciary_alienation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('emissions', function (Blueprint $table) {
            $table->dropColumn([
                'target_audience',
                'distributor',
                'fiduciary_assignment',
                'vehicle_fiduciary_alienation',
                'quota_fiduciary_alienation',
                'surety',
                'real_estate_guarantee',
                'property_fiduciary_alienation',
                'aval',
            ]);
        });
    }
};
