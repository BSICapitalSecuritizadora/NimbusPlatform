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
        Schema::table('business_holidays', function (Blueprint $table) {
            $table->foreignId('business_calendar_legal_rule_id')
                ->nullable()
                ->after('business_calendar_year_id')
                ->constrained('business_calendar_legal_rules')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('business_holidays', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_calendar_legal_rule_id');
        });
    }
};
