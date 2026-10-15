<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allocation revisions: each row is one complete distribution proposal
     * for its period (application + competence). Lifecycle:
     * draft → pending_review → approved, with later corrections superseding
     * the previously effective revision. Approved rows are never updated in
     * place — monetary corrections always create a new revision.
     */
    public function up(): void
    {
        Schema::create('fund_allocation_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fund_allocation_period_id')->constrained()->cascadeOnDelete();
            // 1-based within the period.
            $table->unsignedInteger('revision_number');
            // draft | pending_review | approved | superseded | discarded
            $table->string('status', 30);
            // Exact balance version this distribution was approved against.
            // Explicit short name: the conventional one exceeds MySQL's 64-char identifier limit.
            $table->foreignId('bank_application_balance_snapshot_id')->constrained('bank_application_balance_snapshots', 'id', 'allocation_revisions_snapshot_fk')->restrictOnDelete();
            // Denormalized at approval for an auditable, self-contained record.
            $table->decimal('reference_amount', 15, 2);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('unallocated_amount', 15, 2)->default(0);
            $table->foreignId('proposed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();
            // Idempotency: approval retries with the same key replay instead
            // of duplicating. Stored only on approval.
            $table->string('approval_idempotency_key', 64)->nullable()->unique();
            $table->foreignId('supersedes_revision_id')->nullable()->constrained('fund_allocation_revisions')->restrictOnDelete();
            $table->text('correction_reason')->nullable();
            $table->timestamps();

            $table->unique(
                ['fund_allocation_period_id', 'revision_number'],
                'allocation_revisions_period_number_unique'
            );
            $table->index(['fund_allocation_period_id', 'status'], 'allocation_revisions_period_status_index');
        });

        // The period aggregate points back at its revisions. Added here
        // because the referenced table did not exist when periods were
        // created. Null on delete: revisions are never deleted in the
        // domain (history is preserved), this only avoids lock-in.
        Schema::table('fund_allocation_periods', function (Blueprint $table) {
            $table->foreign('effective_revision_id')
                ->references('id')
                ->on('fund_allocation_revisions')
                ->nullOnDelete();
            $table->foreign('open_revision_id')
                ->references('id')
                ->on('fund_allocation_revisions')
                ->nullOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `fund_allocation_revisions` ADD CONSTRAINT `allocation_revisions_amounts_non_negative` CHECK (`reference_amount` >= 0 AND `total_amount` >= 0 AND `unallocated_amount` >= 0)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fund_allocation_periods', function (Blueprint $table) {
            $table->dropForeign(['effective_revision_id']);
            $table->dropForeign(['open_revision_id']);
        });

        Schema::dropIfExists('fund_allocation_revisions');
    }
};
