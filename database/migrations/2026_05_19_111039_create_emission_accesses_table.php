<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emission_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emission_id')->constrained('emissions')->cascadeOnDelete();
            $table->uuid('token')->unique();
            $table->string('requester_name');
            $table->string('requester_email')->index();
            $table->string('requester_phone', 20);
            $table->string('code_hash');
            $table->text('code_encrypted')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('first_accessed_at')->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['emission_id', 'requester_email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emission_accesses');
    }
};
