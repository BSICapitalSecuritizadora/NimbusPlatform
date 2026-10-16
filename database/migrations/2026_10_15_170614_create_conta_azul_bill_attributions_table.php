<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3B: append-only Emission attribution decisions for staged bills.
     *
     * The bill row carries the single effective decision; this table carries
     * every decision ever made, in order. Corrections insert a new row —
     * they never update or delete a previous one — so the full
     * who-decided-what-when-and-why trail survives for audit.
     *
     * `idempotency_key` makes retried submissions safe: repeating the same
     * decision resolves to the existing row instead of recording a
     * duplicate event.
     */
    public function up(): void
    {
        Schema::create('conta_azul_bill_attributions', function (Blueprint $table) {
            $table->id();
            // Restrict: attribution history is audit evidence and pins its
            // bill; staged bills are never deleted by the domain.
            $table->foreignId('conta_azul_imported_bill_id')->constrained('conta_azul_imported_bills')->restrictOnDelete();
            // attributed | corrected
            $table->string('action', 20);
            $table->foreignId('from_emission_id')->nullable()->constrained('emissions')->nullOnDelete();
            $table->foreignId('to_emission_id')->nullable()->constrained('emissions')->nullOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at');
            $table->text('reason');
            // Source version the decision was taken against. A later import
            // that moves `payload_fingerprint` away from this value is a
            // candidate for renewed review, never a silent invalidation.
            $table->char('source_fingerprint', 64);
            // True when the target Emission was not linked to the bill's
            // BankAccount: exceptional path, explicit justification.
            $table->boolean('is_exceptional')->default(false);
            $table->string('idempotency_key', 64)->unique();
            $table->timestamps();

            $table->index(['conta_azul_imported_bill_id', 'id'], 'ca_bill_attributions_bill_order_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conta_azul_bill_attributions');
    }
};
