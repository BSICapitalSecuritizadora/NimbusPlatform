<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The link between one confirmed reconciliation and the individual changes
     * it produced.
     *
     * The value is the uuid of a `spatie/laravel-activitylog` batch: every
     * activity written while the batch was open carries it, which is what lets
     * "what did this run change" be answered by a key instead of by guessing
     * from the subject and a time window.
     *
     * Nullable because runs recorded before this existed have no batch, and
     * because the correlation is technical -- the `ImportRun` remains the fact
     * of the execution with or without it.
     *
     * No index: it is only ever read from an `ImportRun` already loaded by its
     * primary key. No foreign key either -- `activity_log.batch_uuid` is not a
     * unique key, it repeats once per activity of the batch.
     */
    public function up(): void
    {
        Schema::table('import_runs', function (Blueprint $table) {
            $table->uuid('activity_batch_uuid')->nullable()->after('checksum');
        });
    }

    public function down(): void
    {
        Schema::table('import_runs', function (Blueprint $table) {
            $table->dropColumn('activity_batch_uuid');
        });
    }
};
