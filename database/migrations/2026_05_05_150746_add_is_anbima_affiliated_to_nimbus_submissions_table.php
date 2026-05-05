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
        Schema::table('nimbus_submissions', function (Blueprint $table) {
            $table->boolean('is_anbima_affiliated')
                ->nullable()
                ->after('is_pep');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nimbus_submissions', function (Blueprint $table) {
            $table->dropColumn('is_anbima_affiliated');
        });
    }
};
