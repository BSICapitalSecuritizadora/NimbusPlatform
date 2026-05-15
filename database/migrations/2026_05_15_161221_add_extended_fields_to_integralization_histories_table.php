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
        Schema::table('integralization_histories', function (Blueprint $table) {
            $table->decimal('unit_value', 18, 6)->nullable()->after('quantity');
            $table->decimal('financial_value', 18, 2)->nullable()->after('unit_value');
            $table->string('investor_fund')->nullable()->after('financial_value');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('integralization_histories', function (Blueprint $table) {
            $table->dropColumn([
                'unit_value',
                'financial_value',
                'investor_fund',
            ]);
        });
    }
};
