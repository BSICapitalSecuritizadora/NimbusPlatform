<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Immutable financial reference balances for a physical application.
     *
     * Phase 2A records explicitly supplied manual references only; the
     * `source` column already distinguishes them from future bank-confirmed
     * (`bradesco`) snapshots so history never needs rewriting. New versions
     * supersede older ones — rows are never updated in place.
     */
    public function up(): void
    {
        Schema::create('bank_application_balance_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_application_id')->constrained()->restrictOnDelete();
            // Reporting competence, always normalized to the first day of the month.
            $table->date('competence');
            $table->decimal('amount', 15, 2);
            // Phase 2A writes 'manual' only. Never label a manual row as bank-confirmed.
            $table->string('source', 30);
            $table->dateTime('captured_at');
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();
            // 1-based version within (bank_application_id, competence).
            $table->unsignedInteger('version');
            // Explicit short name: the conventional one exceeds MySQL's 64-char identifier limit.
            $table->foreignId('supersedes_snapshot_id')->nullable()->constrained('bank_application_balance_snapshots', 'id', 'balance_snapshots_supersedes_fk')->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                ['bank_application_id', 'competence', 'version'],
                'balance_snapshots_app_competence_version_unique'
            );
            $table->index(['bank_application_id', 'competence'], 'balance_snapshots_app_competence_index');
        });

        // Defense in depth: the service validates amounts exactly, and the
        // database refuses negatives even on hand-written SQL. MySQL-only:
        // SQLite cannot ADD CONSTRAINT via ALTER TABLE.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `bank_application_balance_snapshots` ADD CONSTRAINT `balance_snapshots_amount_non_negative` CHECK (`amount` >= 0)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_application_balance_snapshots');
    }
};
