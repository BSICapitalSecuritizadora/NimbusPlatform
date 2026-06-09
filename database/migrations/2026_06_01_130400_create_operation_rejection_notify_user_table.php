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
        Schema::create('operation_rejection_notify_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['operation_id', 'user_id']);
        });

        Schema::table('operations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rejection_notify_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('operations', function (Blueprint $table) {
            $table->foreignId('rejection_notify_user_id')->nullable()->after('payment_finalizer_user_id')->constrained('users')->nullOnDelete();
        });

        Schema::dropIfExists('operation_rejection_notify_user');
    }
};
