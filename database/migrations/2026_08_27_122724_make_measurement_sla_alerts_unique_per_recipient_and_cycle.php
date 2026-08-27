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
        Schema::table('measurement_sla_alerts', function (Blueprint $table): void {
            // MySQL may select the old composite unique as the supporting FK index.
            $table->index('measurement_id', 'msa_measurement_fk_idx');
        });

        Schema::table('measurement_sla_alerts', function (Blueprint $table): void {
            $table->dropUnique('msa_unique_alert_day');
            $table->foreignId('recipient_user_id')->nullable()->after('alert_type')->constrained('users')->nullOnDelete();
            $table->dateTime('stage_started_at')->nullable()->after('recipient_user_id');
            $table->unique(
                ['measurement_id', 'stage', 'alert_type', 'recipient_user_id', 'stage_started_at'],
                'msa_unique_recipient_cycle',
            );
            $table->index(['recipient_user_id', 'alert_type'], 'msa_recipient_type_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('measurement_sla_alerts', function (Blueprint $table): void {
            $table->dropUnique('msa_unique_recipient_cycle');
            $table->dropIndex('msa_recipient_type_idx');
            $table->dropConstrainedForeignId('recipient_user_id');
            $table->dropColumn('stage_started_at');
            $table->unique(['measurement_id', 'stage', 'alert_type', 'business_day'], 'msa_unique_alert_day');
        });

        Schema::table('measurement_sla_alerts', function (Blueprint $table): void {
            $table->dropIndex('msa_measurement_fk_idx');
        });
    }
};
