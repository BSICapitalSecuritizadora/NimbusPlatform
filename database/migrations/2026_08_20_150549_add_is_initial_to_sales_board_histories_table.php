<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks the history entry that holds the sales board position captured when
     * the emission left the "Em Elaboração" status. Reuses the existing history
     * table instead of duplicating the sales board structure elsewhere.
     */
    public function up(): void
    {
        Schema::table('sales_board_histories', function (Blueprint $table) {
            $table->boolean('is_initial')->default(false)->after('sales_board_id');

            $table->index(['sales_board_id', 'is_initial']);
        });
    }

    public function down(): void
    {
        Schema::table('sales_board_histories', function (Blueprint $table) {
            $table->dropIndex(['sales_board_id', 'is_initial']);
            $table->dropColumn('is_initial');
        });
    }
};
