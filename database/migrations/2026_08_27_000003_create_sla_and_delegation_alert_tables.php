<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('measurement_sla_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('measurement_id')->constrained('measurements')->cascadeOnDelete();
            $table->unsignedTinyInteger('stage');
            $table->string('alert_type', 20); // warning, overdue
            $table->date('business_day');
            $table->dateTime('notified_at');
            $table->timestamps();

            $table->unique(['measurement_id', 'stage', 'alert_type', 'business_day'], 'msa_unique_alert_day');
            $table->index(['stage', 'alert_type'], 'msa_type_idx');
        });

        Schema::create('delegation_expiration_warnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delegation_id')->constrained('responsibility_delegations')->cascadeOnDelete();
            $table->date('warning_date');
            $table->dateTime('notified_at');
            $table->timestamps();

            $table->unique(['delegation_id', 'warning_date'], 'dew_unique_day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delegation_expiration_warnings');
        Schema::dropIfExists('measurement_sla_alerts');
    }
};
