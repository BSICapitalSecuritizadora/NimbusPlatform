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
        Schema::create('obligation_anchor_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('obligation_series_id')->constrained()->cascadeOnDelete();
            $table->foreignId('obligation_series_rule_id')->constrained()->restrictOnDelete();
            $table->string('event_name');
            $table->date('occurred_on');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(
                ['obligation_series_id', 'occurred_on'],
                'obligation_anchor_events_series_date_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('obligation_anchor_events');
    }
};
