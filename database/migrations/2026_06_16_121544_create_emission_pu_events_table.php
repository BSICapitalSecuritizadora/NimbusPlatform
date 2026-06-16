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
        Schema::create('emission_pu_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emission_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 40);
            $table->date('original_date')->nullable();
            $table->date('effective_date');
            $table->string('amortization_type', 40)->default('none');
            $table->decimal('amortization_value', 24, 16)->nullable();
            $table->unsignedInteger('sequence')->default(1);
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['emission_id', 'event_type', 'effective_date', 'sequence'], 'emission_pu_events_unique');
            $table->index(['emission_id', 'effective_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emission_pu_events');
    }
};
