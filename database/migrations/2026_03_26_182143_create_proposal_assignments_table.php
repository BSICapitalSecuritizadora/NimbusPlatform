<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->constrained('proposals')->cascadeOnDelete();
            $table->foreignId('representative_id')->constrained('proposal_representatives')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('strategy')->default('round_robin');
            $table->timestamp('assigned_at');
            $table->timestamps();

            $table->unique(['proposal_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_assignments');
    }
};
