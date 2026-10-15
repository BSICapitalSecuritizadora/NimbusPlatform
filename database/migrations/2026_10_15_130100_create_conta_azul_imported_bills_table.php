<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3A: staged Conta Azul bills, keyed by global external identity.
     *
     * Canonical identity is the external bill id alone
     * (`conta_azul_bill_id`, globally unique) — the exact scope the legacy
     * integration already treats as unique
     * (`expense_histories.conta_azul_bill_id` is a global unique key and
     * SyncContaAzulExpensesJob looks bills up by id with no account or
     * tenant scope; authentication is a single global ContaAzulToken). No
     * company/tenant scope exists in this integration, so none is invented
     * here: one bill is one row even when several Emissions share the
     * BankAccount it was fetched through.
     *
     * Parentage (`bank_account_id`, `conta_azul_account_id`,
     * `conta_azul_import_batch_id`) records where the bill was FIRST seen
     * and is immutable afterwards; `last_seen_batch_id` advances on every
     * reimport. Content changes are detected through `payload_fingerprint`
     * and traced via `import_status = updated`.
     *
     * `reconciliation_status` starts at `pending` and is never advanced by
     * the ingestion pipeline: attribution is explicit, governed and belongs
     * to Phase 3B. `attributed_emission_id` is Phase 3B-owned schema,
     * always NULL in Phase 3A.
     */
    public function up(): void
    {
        Schema::create('conta_azul_imported_bills', function (Blueprint $table) {
            $table->id();
            // First-seen batch. Restrict: batches are append-only audit
            // history and are never deleted by the domain.
            $table->foreignId('conta_azul_import_batch_id')->constrained('conta_azul_import_batches')->restrictOnDelete();
            // Most recent batch that observed this bill. Advances on every
            // idempotent reimport, changed or not.
            $table->foreignId('last_seen_batch_id')->nullable()->constrained('conta_azul_import_batches')->nullOnDelete();
            $table->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            // Global canonical identity. One bill, one row, regardless of
            // how many Funds or Emissions share the parent BankAccount.
            $table->string('conta_azul_bill_id')->unique();
            // Physical Conta Azul financial account the bill was fetched
            // under, at first-seen time. Immutable provenance.
            $table->string('conta_azul_account_id');
            $table->string('description')->nullable();
            // Normalized money, exact decimal persistence. Mirror the
            // expense_histories precision (15,2).
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->nullable();
            $table->decimal('unpaid_amount', 15, 2)->nullable();
            $table->date('due_date')->nullable();
            // From the listing payload only. Staging never calls the
            // installment endpoint and never invents a payment date: unknown
            // stays NULL (the legacy job's NULL semantics are preserved).
            $table->date('payment_date')->nullable();
            $table->date('competence_date')->nullable();
            $table->dateTime('external_created_at')->nullable();
            $table->dateTime('external_updated_at')->nullable();
            $table->string('external_status', 60)->nullable();
            $table->string('external_status_translated', 60)->nullable();
            // Raw `categorias` array preserved verbatim (JSON). Unmapped
            // categories are KEPT here with mapped_category NULL — staging
            // never skips a bill for lack of mapping.
            $table->json('categories')->nullable();
            $table->string('mapped_category')->nullable();
            // Raw `centros_de_custo` array: verified API field, kept as
            // future attribution evidence (observed empty in audited
            // payloads, so content is not relied upon).
            $table->json('cost_centers')->nullable();
            $table->string('supplier_name')->nullable();
            $table->string('supplier_external_id')->nullable();
            // sha256 over the canonical normalized content. A changed
            // fingerprint on reimport means the external bill changed.
            $table->char('payload_fingerprint', 64);
            // Full raw API payload, encrypted at rest (same convention as
            // ContaAzulToken), hidden from serialization. Provenance for
            // reconciliation; never logged.
            $table->text('source_payload')->nullable();
            // staged | updated | error
            $table->string('import_status', 30)->default('staged');
            // Per-bill processing error (record failed normalization but
            // carried an identity worth preserving). Truncated, sanitized.
            $table->text('last_import_error')->nullable();
            // pending | insufficient_information | ready_for_review |
            // attributed. Ingestion only ever writes pending; the remaining
            // transitions belong to the governed Phase 3B workflow.
            $table->string('reconciliation_status', 30)->default('pending');
            // Phase 3B-owned. Always NULL in Phase 3A: there is no automatic
            // bill-to-Emission attribution.
            $table->foreignId('attributed_emission_id')->nullable()->constrained('emissions')->nullOnDelete();
            $table->timestamps();

            $table->index(['bank_account_id', 'reconciliation_status'], 'ca_imported_bills_account_recon_index');
            $table->index('conta_azul_account_id', 'ca_imported_bills_ca_account_index');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `conta_azul_imported_bills` ADD CONSTRAINT `ca_imported_bills_amounts_non_negative` CHECK (`total_amount` >= 0 AND (`paid_amount` IS NULL OR `paid_amount` >= 0) AND (`unpaid_amount` IS NULL OR `unpaid_amount` >= 0))');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conta_azul_imported_bills');
    }
};
