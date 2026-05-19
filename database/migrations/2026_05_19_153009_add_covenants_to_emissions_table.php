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
        Schema::table('emissions', function (Blueprint $table) {
            $table->longText('covenants')->nullable()->after('guarantees_description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('emissions', function (Blueprint $table) {
            $table->dropColumn('covenants');
        });
    }
};
