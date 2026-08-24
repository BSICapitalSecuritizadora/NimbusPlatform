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
        Schema::table('extracted_obligations', function (Blueprint $table) {
            $table->json('schedule_suggestion')->nullable()->after('due_rule');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('extracted_obligations', function (Blueprint $table) {
            $table->dropColumn('schedule_suggestion');
        });
    }
};
