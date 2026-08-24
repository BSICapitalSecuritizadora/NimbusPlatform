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
        Schema::create('business_calendar_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_calendar_year_id')->nullable()->constrained('business_calendar_years')->nullOnDelete();
            $table->string('calendar_code', 20);
            $table->date('calendar_date');
            $table->text('reason');
            $table->boolean('previous_is_business_day');
            $table->boolean('new_is_business_day');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('applied_at');
            $table->unsignedBigInteger('revision');
            $table->timestamps();

            $table->index(['calendar_code', 'calendar_date', 'applied_at'], 'business_calendar_overrides_lookup_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_calendar_overrides');
    }
};
