<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emissions', function (Blueprint $table) {
            $table->string('negotiations_source', 20)->default('legacy')->after('is_public');
            $table->index('negotiations_source');
        });

        // Backfill existing emissions that already have contracts to 'contracts' only if they clearly have full import?
        // For safety, keep all as 'legacy' by default — explicit migration required.
        // No automatic backfill to avoid partial-data risk.
    }

    public function down(): void
    {
        Schema::table('emissions', function (Blueprint $table) {
            $table->dropIndex(['negotiations_source']);
            $table->dropColumn('negotiations_source');
        });
    }
};
