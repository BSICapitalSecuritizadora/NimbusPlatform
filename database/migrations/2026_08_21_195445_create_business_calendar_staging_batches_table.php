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
        Schema::create('business_calendar_staging_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch_uuid')->unique();
            $table->string('calendar_code', 64);
            $table->unsignedSmallInteger('year');
            $table->string('source', 120);
            $table->boolean('source_is_official')->default(false);
            $table->text('source_document');
            $table->string('source_revision', 100)->nullable();
            $table->string('checksum', 64);
            $table->string('status', 30)->default('pending_review');
            $table->unsignedInteger('records_staged')->default(0);
            $table->foreignId('staged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('staged_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();

            $table->foreign('calendar_code')
                ->references('code')
                ->on('business_calendars')
                ->restrictOnDelete();
            $table->index(['calendar_code', 'year', 'status'], 'calendar_staging_batches_lookup_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_calendar_staging_batches');
    }
};
