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
        Schema::create('business_calendar_dates', function (Blueprint $table) {
            $table->id();
            $table->string('calendar_code', 20)->default('B3');
            $table->date('calendar_date');
            $table->boolean('is_business_day')->default(true);
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['calendar_code', 'calendar_date']);
            $table->index(['calendar_code', 'is_business_day', 'calendar_date'], 'bcd_lookup_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_calendar_dates');
    }
};
