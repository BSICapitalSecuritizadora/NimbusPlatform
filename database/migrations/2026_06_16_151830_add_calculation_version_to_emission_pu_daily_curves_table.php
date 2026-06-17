<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emission_pu_daily_curves', function (Blueprint $table) {
            $table->string('calculation_version', 40)
                ->default('v1')
                ->after('curve_date');
            $table->decimal('amortization_value', 30, 16)
                ->default(0)
                ->after('amortization_unit_value');

            $table->dropUnique('emission_pu_daily_curves_emission_id_curve_date_unique');
            $table->unique(
                ['emission_id', 'curve_date', 'calculation_version'],
                'emission_pu_daily_curves_emission_date_version_unique',
            );
            $table->index(
                ['emission_id', 'calculation_version', 'curve_date'],
                'emission_pu_daily_curves_version_lookup_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('emission_pu_daily_curves', function (Blueprint $table) {
            $table->dropIndex('emission_pu_daily_curves_version_lookup_index');
            $table->dropUnique('emission_pu_daily_curves_emission_date_version_unique');
            $table->unique(['emission_id', 'curve_date']);
            $table->dropColumn(['calculation_version', 'amortization_value']);
        });
    }
};
