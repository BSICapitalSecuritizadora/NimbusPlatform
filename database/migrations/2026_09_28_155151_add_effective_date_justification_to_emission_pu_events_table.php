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
        Schema::table('emission_pu_events', function (Blueprint $table) {
            $table->string('effective_date_reason', 32)->nullable()->after('effective_date');
            $table->text('effective_date_justification')->nullable()->after('effective_date_reason');
            $table->string('effective_date_evidence_reference')->nullable()->after('effective_date_justification');
            $table->text('effective_date_evidence_excerpt')->nullable()->after('effective_date_evidence_reference');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('emission_pu_events', function (Blueprint $table) {
            $table->dropColumn([
                'effective_date_reason',
                'effective_date_justification',
                'effective_date_evidence_reference',
                'effective_date_evidence_excerpt',
            ]);
        });
    }
};
