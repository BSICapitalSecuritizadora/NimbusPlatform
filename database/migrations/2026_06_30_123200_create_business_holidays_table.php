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
        Schema::create('business_holidays', function (Blueprint $table) {
            $table->id();
            $table->string('calendar_code', 20)->default('B3');
            $table->date('holiday_date');
            $table->string('name')->nullable();
            $table->string('source', 40)->default('anbima');
            $table->string('source_file')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['calendar_code', 'holiday_date', 'source'], 'business_holidays_unique');
            $table->index(['calendar_code', 'holiday_date'], 'business_holidays_lookup_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_holidays');
    }
};
