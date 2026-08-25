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
        Schema::table('business_calendars', function (Blueprint $table) {
            $table->string('materialization_policy', 60)
                ->default('explicit_official_decisions')
                ->after('import_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('business_calendars', function (Blueprint $table) {
            $table->dropColumn('materialization_policy');
        });
    }
};
