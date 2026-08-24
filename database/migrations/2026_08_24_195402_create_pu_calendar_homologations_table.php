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
        Schema::create('pu_calendar_homologations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emission_id')->constrained()->cascadeOnDelete();
            $table->string('candidate_calendar_code', 64);
            $table->string('purpose', 80)->default('cdi_accrual_dup_and_lookup_lag');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 40)->default('draft');
            $table->string('decision', 40)->nullable();
            $table->json('legacy_parameter_snapshot');
            $table->json('candidate_parameter_snapshot');
            $table->json('evidence_matrix')->nullable();
            $table->json('calendar_governance_snapshot')->nullable();
            $table->json('external_reference')->nullable();
            $table->json('result_summary')->nullable();
            $table->json('daily_diff')->nullable();
            $table->json('first_divergence')->nullable();
            $table->char('comparison_checksum', 64)->nullable();
            $table->text('conclusion_notes')->nullable();
            $table->foreignId('analyzed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->foreign('candidate_calendar_code')
                ->references('code')
                ->on('business_calendars');
            $table->index(['emission_id', 'status'], 'pu_calendar_homologations_emission_status_index');
            $table->index(['candidate_calendar_code', 'status'], 'pu_calendar_homologations_calendar_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pu_calendar_homologations');
    }
};
