<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emission_pu_parameters', function (Blueprint $table): void {
            $table->decimal('annual_rate', 12, 8)->nullable()->after('spread_rate');
            $table->string('calculation_method', 40)->nullable()->after('indexer');
            $table->string('method_version', 40)->nullable()->after('calculation_method');
            $table->string('rounding_policy', 40)->nullable()->default('standard')->after('method_version');

            // Campos preparatórios para IPCA (não homologado nesta fase).
            $table->unsignedTinyInteger('index_lag_months')->nullable()->after('index_rate_lag_business_days');
            $table->date('base_index_date')->nullable()->after('index_lag_months');
            $table->string('correction_frequency', 20)->nullable()->after('base_index_date');
            $table->string('index_projection_policy', 40)->nullable()->after('correction_frequency');

            // Prefixado não usa spread; CDI continua exigindo via validação.
            $table->decimal('spread_rate', 12, 8)->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('emission_pu_parameters')->whereNull('spread_rate')->update(['spread_rate' => 0]);

        Schema::table('emission_pu_parameters', function (Blueprint $table): void {
            $table->decimal('spread_rate', 12, 8)->nullable(false)->change();

            $table->dropColumn([
                'annual_rate',
                'calculation_method',
                'method_version',
                'rounding_policy',
                'index_lag_months',
                'base_index_date',
                'correction_frequency',
                'index_projection_policy',
            ]);
        });
    }
};
