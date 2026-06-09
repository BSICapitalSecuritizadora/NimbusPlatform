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
        Schema::create('operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('construction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code')->nullable()->unique();
            $table->string('title');
            $table->string('status')->default('draft');
            $table->string('issuer')->nullable();
            $table->decimal('amount', 18, 2)->nullable();
            $table->decimal('construction_fund_amount', 18, 2)->nullable();
            $table->date('due_date')->nullable();
            $table->date('next_measurement_at')->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('stage2_reviewer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('stage3_reviewer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('payment_manager_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('payment_finalizer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rejection_notify_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('emission_id');
            $table->index('construction_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operations');
    }
};
