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
        Schema::table('measurement_payments', function (Blueprint $table) {
            $table->foreignId('financial_rule_id')->nullable()->constrained('measurement_financial_rules', indexName: 'payment_financial_rule_fk')->restrictOnDelete();
            $table->json('financial_assessment')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('measurement_payments', function (Blueprint $table) {
            $table->dropForeign('payment_financial_rule_fk');
            $table->dropColumn(['financial_rule_id', 'financial_assessment']);
        });
    }
};
