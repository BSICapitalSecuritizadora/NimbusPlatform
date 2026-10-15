<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3A: account-level Conta Azul import batches.
     *
     * One row per ingestion attempt against ONE physical Conta Azul
     * financial account (via its BankAccount), never per Fund: bills are
     * fetched once per physical account even when several Funds or
     * Emissions share the BankAccount.
     *
     * The counters plus the terminal status are the completeness contract:
     * `completed` is only ever written when the page stream ended without
     * failure and records_received reached the advertised total. Anything
     * else stays `partial` (some pages staged) or `failed` (nothing usable),
     * both retriable by starting a NEW batch linked through
     * `retries_batch_id` — a batch row is never rewritten into a new
     * attempt, so every attempt keeps its own audit trail.
     *
     * Staging only: nothing here is read by Expense, Guarantee, allocation
     * or report readers. The legacy SyncContaAzulExpensesJob keeps running
     * untouched until an explicitly authorized Phase 3C cutover.
     */
    public function up(): void
    {
        Schema::create('conta_azul_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            // Conta Azul financial account identity as resolved at import
            // time. Denormalized on purpose: if the BankAccount mapping is
            // ever corrected, this row still proves which physical account
            // the bills below were fetched from.
            $table->string('conta_azul_account_id');
            // Integration context that produced this batch (API version /
            // source label). Part of the logical import identity together
            // with the account and the date range.
            $table->string('integration_context', 60)->default('conta_azul');
            // Explicit requested window. Staging never invents a range.
            $table->date('date_from');
            $table->date('date_to');
            // pending | running | completed | partial | failed
            $table->string('status', 30)->default('pending');
            // Completeness counters. Monotonic within the batch; pages and
            // records retrieved, bills staged/updated/skipped per page.
            $table->unsignedInteger('pages_requested')->default(0);
            $table->unsignedInteger('pages_completed')->default(0);
            $table->unsignedInteger('records_received')->default(0);
            $table->unsignedInteger('bills_staged')->default(0);
            $table->unsignedInteger('bills_updated')->default(0);
            $table->unsignedInteger('bills_skipped')->default(0);
            // Re-observed unchanged: already known bills seen again. Tracked
            // separately so bills_staged keeps meaning "newly staged".
            $table->unsignedInteger('bills_reobserved')->default(0);
            // Machine-readable failure classification plus a truncated,
            // adapter-sanitized human detail. Never holds secrets or full
            // banking identifiers.
            $table->string('error_code', 60)->nullable();
            $table->text('error_message')->nullable();
            // Retry lineage: a retry is a new batch pointing at the
            // partial/failed batch it supersedes.
            $table->foreignId('retries_batch_id')->nullable()->constrained('conta_azul_import_batches')->nullOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->index(['bank_account_id', 'status'], 'ca_import_batches_account_status_index');
            $table->index(['bank_account_id', 'date_from', 'date_to'], 'ca_import_batches_account_range_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conta_azul_import_batches');
    }
};
