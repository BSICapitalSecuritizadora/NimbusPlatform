<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Turns the sales board history into a full version log: who recorded each
     * version and, when the competence was already registered, why it changed.
     */
    public function up(): void
    {
        Schema::table('sales_board_histories', function (Blueprint $table) {
            $table->foreignId('changed_by_id')
                ->nullable()
                ->after('is_initial')
                ->constrained('users')
                ->nullOnDelete();

            $table->text('change_reason')->nullable()->after('changed_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales_board_histories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('changed_by_id');
            $table->dropColumn('change_reason');
        });
    }
};
