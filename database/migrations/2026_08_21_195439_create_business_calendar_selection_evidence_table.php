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
        Schema::create('business_calendar_selection_evidence', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('subject', 'calendar_selection_evidence_subject_index');
            $table->string('context', 80);
            $table->string('calendar_code', 64);
            $table->text('source_document')->nullable();
            $table->string('clause_reference')->nullable();
            $table->string('page_reference', 100)->nullable();
            $table->text('excerpt')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('calendar_code')
                ->references('code')
                ->on('business_calendars')
                ->restrictOnDelete();
            $table->index(['calendar_code', 'context'], 'calendar_selection_evidence_lookup_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_calendar_selection_evidence');
    }
};
