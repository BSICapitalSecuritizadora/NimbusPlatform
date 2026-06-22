<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emission_pu_curve_versions', function (Blueprint $table): void {
            $table->string('obsolete_reason')->nullable()->after('status');
        });

        DB::table('emission_pu_curve_versions')
            ->where('status', 'obsolete')
            ->whereNull('obsolete_reason')
            ->update(['obsolete_reason' => 'legacy_backfill']);
    }

    public function down(): void
    {
        Schema::table('emission_pu_curve_versions', function (Blueprint $table): void {
            $table->dropColumn('obsolete_reason');
        });
    }
};
