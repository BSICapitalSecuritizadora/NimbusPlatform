<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_configurations', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('stage')->unique()->comment('Workflow stage 1-5');
            $table->unsignedInteger('duration_value')->comment('e.g., 5');
            $table->string('duration_unit', 10)->default('days')->comment('days or hours');
            $table->unsignedTinyInteger('warning_threshold_percent')->default(75);
            $table->unsignedTinyInteger('escalation_threshold_percent')->default(100);
            $table->boolean('exclude_weekends')->default(true);
            $table->boolean('exclude_holidays')->default(true);
            $table->boolean('exclude_paused_time')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_configurations');
    }
};
