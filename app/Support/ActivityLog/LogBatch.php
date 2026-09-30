<?php

namespace App\Support\ActivityLog;

use App\Providers\AppServiceProvider;
use Closure;
use Illuminate\Support\Str;

/**
 * Groups every activity written while a unit of work runs under one uuid.
 *
 * `spatie/laravel-activitylog` shipped this until v4 and removed it in v5. It
 * lives here now because the correlation it produces is not optional: an
 * import run points at `activity_log.batch_uuid` to answer "what did this
 * execution change", and that has to keep excluding the manual edit made on the
 * same record a minute later.
 *
 * Bound as scoped in {@see AppServiceProvider}, so one request or one queued
 * job never inherits the batch of another. The uuid reaches the activity
 * through {@see LogActivityWithinBatch}.
 */
class LogBatch
{
    private ?string $uuid = null;

    private int $depth = 0;

    /**
     * Runs the callback inside a batch and hands it the uuid.
     *
     * Nested calls share the outermost uuid. The batch is closed in a `finally`:
     * a callback that throws must not leave it open for whatever the same
     * process logs next.
     *
     * @template TReturn
     *
     * @param  Closure(string): TReturn  $callback
     * @return TReturn
     */
    public function withinBatch(Closure $callback): mixed
    {
        $this->startBatch();

        try {
            return $callback($this->uuid);
        } finally {
            $this->endBatch();
        }
    }

    /**
     * Reopens a batch that already has a uuid, so later work can join it.
     */
    public function setBatch(string $uuid): void
    {
        $this->uuid = $uuid;
        $this->depth = 1;
    }

    public function endBatch(): void
    {
        $this->depth = max(0, $this->depth - 1);

        if ($this->depth === 0) {
            $this->uuid = null;
        }
    }

    public function isOpen(): bool
    {
        return $this->depth > 0;
    }

    public function getUuid(): ?string
    {
        return $this->uuid;
    }

    private function startBatch(): void
    {
        if (! $this->isOpen()) {
            $this->uuid = (string) Str::uuid();
        }

        $this->depth++;
    }
}
