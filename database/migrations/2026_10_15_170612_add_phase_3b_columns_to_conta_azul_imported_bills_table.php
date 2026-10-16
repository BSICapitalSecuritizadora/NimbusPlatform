<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3B: governed attribution state on staged bills.
     *
     * The effective decision lives on the bill row (one bill, one Emission);
     * every decision ever made lives append-only in
     * `conta_azul_bill_attributions`. `attribution_source_fingerprint` pins
     * the source version the effective decision was taken against, so a
     * later import update can never silently invalidate it:
     * attribution-relevant changes raise `needs_re_review` instead.
     *
     * `content_batch_id` is the batch that last established the current
     * content (created it or changed it — re-observation alone never moves
     * it). Attribution eligibility reads completeness from this verified
     * observation, never blindly from the first-seen batch.
     *
     * Backfill is best-effort for pre-3B rows: bills whose content still is
     * the first-seen one point at the first batch, the rest at the most
     * recent observer.
     */
    public function up(): void
    {
        Schema::table('conta_azul_imported_bills', function (Blueprint $table) {
            $table->foreignId('content_batch_id')->nullable()->after('last_seen_batch_id')->constrained('conta_azul_import_batches')->nullOnDelete();
            $table->char('attribution_source_fingerprint', 64)->nullable()->after('attributed_emission_id');
            $table->foreignId('attributed_by_user_id')->nullable()->after('attribution_source_fingerprint')->constrained('users')->nullOnDelete();
            $table->dateTime('attributed_at')->nullable()->after('attributed_by_user_id');
            $table->text('attribution_reason')->nullable()->after('attributed_at');
            $table->boolean('needs_re_review')->default(false)->after('attribution_reason');
            $table->text('re_review_reason')->nullable()->after('needs_re_review');
            $table->char('previous_payload_fingerprint', 64)->nullable()->after('re_review_reason');
            $table->text('reconciliation_note')->nullable()->after('previous_payload_fingerprint');

            $table->index('needs_re_review', 'ca_imported_bills_needs_review_index');
        });

        DB::table('conta_azul_imported_bills')
            ->where('import_status', 'staged')
            ->update(['content_batch_id' => DB::raw('conta_azul_import_batch_id')]);

        DB::table('conta_azul_imported_bills')
            ->where('import_status', '!=', 'staged')
            ->update(['content_batch_id' => DB::raw('COALESCE(last_seen_batch_id, conta_azul_import_batch_id)')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conta_azul_imported_bills', function (Blueprint $table) {
            $table->dropIndex('ca_imported_bills_needs_review_index');
            $table->dropConstrainedForeignId('content_batch_id');
            $table->dropConstrainedForeignId('attributed_by_user_id');
            $table->dropColumn([
                'attribution_source_fingerprint',
                'attributed_at',
                'attribution_reason',
                'needs_re_review',
                're_review_reason',
                'previous_payload_fingerprint',
                'reconciliation_note',
            ]);
        });
    }
};
