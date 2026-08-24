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
        Schema::create('business_calendar_import_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch_uuid')->index();
            $table->foreignId('business_calendar_year_id')->nullable()->constrained('business_calendar_years')->nullOnDelete();
            $table->string('calendar_code', 20);
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('source', 80);
            $table->boolean('source_is_official')->default(false);
            $table->text('source_url')->nullable();
            $table->string('source_file')->nullable();
            $table->text('source_document')->nullable();
            $table->string('source_revision', 100)->nullable();
            $table->string('checksum', 64)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('triggered_by_process', 120);
            $table->unsignedInteger('records_found')->default(0);
            $table->unsignedInteger('records_inserted')->default(0);
            $table->unsignedInteger('records_changed')->default(0);
            $table->unsignedInteger('removals_detected')->default(0);
            $table->unsignedInteger('conflicts_detected')->default(0);
            $table->json('errors')->nullable();
            $table->string('result', 30);
            $table->boolean('dry_run')->default(false);
            $table->timestamps();

            $table->index(['calendar_code', 'year', 'started_at'], 'business_calendar_import_runs_lookup_index');
            $table->index(['calendar_code', 'result', 'started_at'], 'business_calendar_import_runs_result_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_calendar_import_runs');
    }
};
