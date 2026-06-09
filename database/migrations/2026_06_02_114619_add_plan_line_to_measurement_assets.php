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
        Schema::table('measurement_assets', function (Blueprint $table) {
            $table->foreignId('plan_line_id')
                ->nullable()
                ->after('plan_set_id')
                ->constrained('measurement_plan_lines')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('measurement_assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_line_id');
        });
    }
};
