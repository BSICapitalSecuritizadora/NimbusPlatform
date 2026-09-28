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
        Schema::table('emission_pu_curve_versions', function (Blueprint $table) {
            $table->boolean('self_homologated')->default(false)->after('homologated_at');
            $table->text('homologation_justification')->nullable()->after('self_homologated');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('emission_pu_curve_versions', function (Blueprint $table) {
            $table->dropColumn(['self_homologated', 'homologation_justification']);
        });
    }
};
