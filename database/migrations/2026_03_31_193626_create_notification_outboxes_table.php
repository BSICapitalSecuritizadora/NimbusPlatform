<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nimbus_notification_outboxes', function (Blueprint $table) {
            $table->id();
            $table->string('type', 100);
            $table->string('recipient_email', 190);
            $table->string('recipient_name', 190)->nullable();
            $table->string('subject', 255);
            $table->string('template', 100);
            $table->json('payload_json');
            $table->string('correlation_id', 100)->nullable();
            $table->enum('status', ['PENDING', 'SENDING', 'SENT', 'FAILED', 'CANCELLED'])->default('PENDING');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->dateTime('next_attempt_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at']);
            $table->index('recipient_email');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nimbus_notification_outboxes');
    }
};
