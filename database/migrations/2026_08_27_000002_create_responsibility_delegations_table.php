<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('responsibility_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delegator_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('delegate_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('scope_type'); // global, operation, stage
            $table->foreignId('scope_operation_id')->nullable()->constrained('operations')->cascadeOnDelete();
            $table->unsignedTinyInteger('scope_stage')->nullable(); // 1-5
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->text('reason');
            $table->dateTime('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['delegate_user_id', 'starts_at', 'ends_at', 'revoked_at'], 'rd_delegate_active_idx');
            $table->index(['delegator_user_id', 'revoked_at'], 'rd_delegator_idx');
            $table->index(['scope_type', 'scope_operation_id'], 'rd_scope_idx');
            $table->index(['scope_stage'], 'rd_stage_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('responsibility_delegations');
    }
};
