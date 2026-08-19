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
        Schema::table('obligation_series', function (Blueprint $table) {
            $table->unsignedSmallInteger('due_offset_days')->nullable()->after('due_offset_months');
        });

        Schema::table('obligation_series_rules', function (Blueprint $table) {
            $table->unsignedSmallInteger('due_offset_days')->nullable()->after('due_offset_months');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('obligation_series_rules', function (Blueprint $table) {
            $table->dropColumn('due_offset_days');
        });

        Schema::table('obligation_series', function (Blueprint $table) {
            $table->dropColumn('due_offset_days');
        });
    }
};
