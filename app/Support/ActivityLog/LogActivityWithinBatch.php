<?php

namespace App\Support\ActivityLog;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Actions\LogActivityAction;

/**
 * Stamps the open {@see LogBatch} on every activity before it is saved.
 *
 * Registered in `config/activitylog.php` as the package's `log_activity`
 * action, which is the single place both the model events and the explicit
 * `activity()->log()` calls go through.
 */
class LogActivityWithinBatch extends LogActivityAction
{
    public function __construct(private readonly LogBatch $batch) {}

    public function execute(Model $activity, string $description): Model
    {
        if ($this->batch->isOpen()) {
            $activity->batch_uuid ??= $this->batch->getUuid();
        }

        return parent::execute($activity, $description);
    }
}
