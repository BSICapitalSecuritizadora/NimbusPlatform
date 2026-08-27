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
        Schema::create('index_rate_source_governance_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('source_code', 64);
            $table->char('report_checksum', 64)->unique();
            $table->string('artifact_disk', 64);
            $table->string('artifact_path');
            $table->string('status', 32);
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at');
            $table->text('review_notes');
            $table->timestamps();

            $table->index(['source_code', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('index_rate_source_governance_reviews');
    }
};
