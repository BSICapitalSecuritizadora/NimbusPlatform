<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->constrained()->cascadeOnDelete();
            $table->string('previous_status')->nullable();
            $table->string('new_status');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index(['proposal_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_status_histories');
    }
};
