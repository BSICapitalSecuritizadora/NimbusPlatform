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
        Schema::table('constructions', function (Blueprint $table) {
            $table->string('development_trade_name')->nullable()->after('development_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('constructions', function (Blueprint $table) {
            $table->dropColumn('development_trade_name');
        });
    }
};
