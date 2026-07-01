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
        Schema::create('reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->string('type')->comment('E.g., obligation_due, proposal_stale, evidence_rejected');
            $table->string('channel')->comment('E.g., email, database, teams');
            $table->string('status')->default('sent')->comment('sent, failed');
            $table->nullableMorphs('notifiable');
            $table->nullableMorphs('related');
            $table->string('recipient_email')->nullable();
            $table->string('severity')->default('info');
            $table->string('reason')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['status', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reminder_logs');
    }
};
