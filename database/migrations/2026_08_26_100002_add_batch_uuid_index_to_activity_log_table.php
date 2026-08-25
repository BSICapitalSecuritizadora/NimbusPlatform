<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `activity_log` grows forever and the package indexes only `log_name`,
     * `subject` and `causer` -- reading the changes of one import run filters by
     * `batch_uuid`, which without this index means scanning the whole table on
     * every page of the listing.
     */
    public function up(): void
    {
        Schema::connection(config('activitylog.database_connection'))->table(
            config('activitylog.table_name'),
            function (Blueprint $table) {
                $table->index('batch_uuid', 'activity_log_batch_uuid_index');
            },
        );
    }

    public function down(): void
    {
        Schema::connection(config('activitylog.database_connection'))->table(
            config('activitylog.table_name'),
            function (Blueprint $table) {
                $table->dropIndex('activity_log_batch_uuid_index');
            },
        );
    }
};
