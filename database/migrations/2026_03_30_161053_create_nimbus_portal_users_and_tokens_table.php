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
        Schema::create('nimbus_portal_users', function (Blueprint $table) {
            $table->id();
            $table->string('full_name', 190);
            $table->string('email', 190)->nullable()->unique();
            $table->string('document_number', 50)->nullable()->unique();
            $table->string('phone_number', 50)->nullable();
            $table->string('external_id', 100)->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['INVITED', 'ACTIVE', 'INACTIVE', 'BLOCKED'])->default('INVITED');
            $table->dateTime('last_login_at')->nullable();
            $table->string('last_login_method', 50)->nullable();
            $table->timestamps();
        });

        Schema::create('nimbus_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nimbus_portal_user_id')->constrained('nimbus_portal_users')->cascadeOnDelete();
            $table->string('code', 64)->unique();
            $table->enum('status', ['PENDING', 'USED', 'REVOKED'])->default('PENDING');
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->string('used_ip', 45)->nullable();
            $table->string('used_user_agent', 255)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nimbus_access_tokens');
        Schema::dropIfExists('nimbus_portal_users');
    }
};
