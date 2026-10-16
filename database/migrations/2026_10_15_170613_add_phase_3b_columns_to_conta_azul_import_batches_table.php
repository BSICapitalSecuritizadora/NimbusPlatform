<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3B: stale-batch recovery state.
     *
     * `last_heartbeat_at` advances on batch creation, every staged page and
     * finalization. A `running` batch whose heartbeat is older than
     * `conta-azul.stale_batch_timeout_minutes` is abandoned work (e.g. the
     * process died to SIGKILL); anything fresher is a protected active
     * import. Recovery never deletes staged bills — it only moves the batch
     * to the terminal, retriable `abandoned` status with full actor/reason
     * provenance, so a retry can reference it.
     */
    public function up(): void
    {
        Schema::table('conta_azul_import_batches', function (Blueprint $table) {
            $table->dateTime('last_heartbeat_at')->nullable()->after('finished_at');
            $table->foreignId('recovered_by_user_id')->nullable()->after('last_heartbeat_at')->constrained('users')->nullOnDelete();
            $table->dateTime('recovered_at')->nullable()->after('recovered_by_user_id');
            $table->string('recovered_from_status', 30)->nullable()->after('recovered_at');
            $table->text('recovery_reason')->nullable()->after('recovered_from_status');

            $table->index(['status', 'last_heartbeat_at'], 'ca_import_batches_status_heartbeat_index');
        });

        DB::table('conta_azul_import_batches')->update([
            'last_heartbeat_at' => DB::raw('COALESCE(finished_at, started_at, created_at)'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conta_azul_import_batches', function (Blueprint $table) {
            $table->dropIndex('ca_import_batches_status_heartbeat_index');
            $table->dropConstrainedForeignId('recovered_by_user_id');
            $table->dropColumn([
                'last_heartbeat_at',
                'recovered_at',
                'recovered_from_status',
                'recovery_reason',
            ]);
        });
    }
};
