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
        Schema::table('obligations', function (Blueprint $table) {
            $table->string('due_date_calculation_status', 30)
                ->nullable()
                ->after('due_date_resolution')
                ->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('obligations', function (Blueprint $table) {
            $table->dropColumn('due_date_calculation_status');
        });
    }
};
