<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `value_source` nulo é o comportamento de sempre (valor da planilha ou do
     * cadastro manual). `official_curve` marca o valor calculado pela curva
     * oficial; o que estava antes fica nas colunas `expected_*`.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('value_source', 20)->nullable()->after('extra_amortization_value');
            $table->decimal('expected_premium_value', 15, 2)->nullable()->after('value_source');
            $table->decimal('expected_interest_value', 15, 2)->nullable()->after('expected_premium_value');
            $table->decimal('expected_amortization_value', 15, 2)->nullable()->after('expected_interest_value');
            $table->decimal('expected_extra_amortization_value', 15, 2)->nullable()->after('expected_amortization_value');
            $table->foreignId('pu_curve_version_id')->nullable()->after('expected_extra_amortization_value')
                ->constrained('emission_pu_curve_versions')->nullOnDelete();
            $table->timestamp('calculated_at')->nullable()->after('pu_curve_version_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pu_curve_version_id');
            $table->dropColumn([
                'value_source',
                'expected_premium_value',
                'expected_interest_value',
                'expected_amortization_value',
                'expected_extra_amortization_value',
                'calculated_at',
            ]);
        });
    }
};
