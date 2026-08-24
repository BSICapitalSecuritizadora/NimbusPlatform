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
        Schema::create('business_calendar_staged_dates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_calendar_staging_batch_id');
            $table->date('calendar_date');
            $table->boolean('is_business_day');
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(
                ['business_calendar_staging_batch_id', 'calendar_date'],
                'business_calendar_staged_dates_unique',
            );
            $table->foreign('business_calendar_staging_batch_id', 'calendar_staged_dates_batch_fk')
                ->references('id')
                ->on('business_calendar_staging_batches')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_calendar_staged_dates');
    }
};
