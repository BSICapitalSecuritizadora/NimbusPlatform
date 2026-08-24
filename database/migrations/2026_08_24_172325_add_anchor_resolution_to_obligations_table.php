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
            $table->foreignId('obligation_anchor_event_id')
                ->nullable()
                ->unique()
                ->after('obligation_series_rule_id')
                ->constrained()
                ->nullOnDelete();
            $table->json('due_date_resolution')->nullable()->after('due_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('obligations', function (Blueprint $table) {
            $table->dropColumn('due_date_resolution');
            $table->dropConstrainedForeignId('obligation_anchor_event_id');
        });
    }
};
