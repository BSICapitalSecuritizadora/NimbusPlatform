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
        Schema::create('business_calendar_years', function (Blueprint $table) {
            $table->id();
            $table->string('calendar_code', 20);
            $table->unsignedSmallInteger('year');
            $table->string('status', 20)->default('provisional');
            $table->string('source', 80)->nullable();
            $table->boolean('source_is_official')->default(false);
            $table->text('source_document')->nullable();
            $table->string('source_revision', 100)->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->string('checksum', 64)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['calendar_code', 'year'], 'business_calendar_years_unique');
            $table->index(['calendar_code', 'status', 'year'], 'business_calendar_years_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_calendar_years');
    }
};
