<?php

use App\Support\ActivityLog\LogActivityWithinBatch;
use Spatie\Activitylog\Actions\CleanActivityLogAction;
use Spatie\Activitylog\Models\Activity;

return [

    /*
     * If set to false, no activities will be saved to the database.
     *
     * The package renamed the variable in v5. The old name is still honoured so
     * an environment that turned logging off before the upgrade stays off.
     */
    'enabled' => env('ACTIVITYLOG_ENABLED', env('ACTIVITY_LOGGER_ENABLED', true)),

    /*
     * When the clean command is executed, all recording activities older than
     * the number of days specified here will be deleted.
     *
     * Only `activitylog:clean` reads this, and nothing schedules it: retention
     * is decided by `audit:clean-filtered` from `config/audit.php`.
     */
    'clean_after_days' => 365,

    /*
     * If no log name is passed to the activity() helper
     * we use this default log name.
     */
    'default_log_name' => 'default',

    /*
     * You can specify an auth driver here that gets user models.
     * If this is null we'll use the current Laravel auth driver.
     */
    'default_auth_driver' => null,

    /*
     * If set to true, the subject relationship on activities
     * will include soft deleted models.
     */
    'include_soft_deleted_subjects' => false,

    /*
     * This model will be used to log activity.
     * It should implement the Spatie\Activitylog\Contracts\Activity interface
     * and extend Illuminate\Database\Eloquent\Model.
     */
    'activity_model' => Activity::class,

    /*
     * These attributes will be excluded from logging for all models.
     * Model-specific exclusions via logExcept() are merged with these.
     */
    'default_except_attributes' => [],

    /*
     * When enabled, activities are buffered in memory and inserted in a
     * single bulk query after the response has been sent to the client.
     *
     * Left off on purpose: the import reconciliations rely on each activity
     * being written by the same transaction as the record it describes, so a
     * rolled back import leaves no trail behind.
     */
    'buffer' => [
        'enabled' => env('ACTIVITYLOG_BUFFER_ENABLED', false),
    ],

    /*
     * These action classes can be overridden to customize how activities
     * are logged and cleaned. Your custom classes must extend the originals.
     *
     * `log_activity` is ours: v5 dropped the batch system, and the import runs
     * are correlated to what they changed through `activity_log.batch_uuid`.
     */
    'actions' => [
        'log_activity' => LogActivityWithinBatch::class,
        'clean_log' => CleanActivityLogAction::class,
    ],

    /*
     * No longer read by the package: v5 fixed the table name on the model and
     * dropped the connection override. Kept only because the migrations
     * published by v4 resolve the table through these two keys, and a fresh
     * database still runs them.
     */
    'table_name' => 'activity_log',

    'database_connection' => null,
];
