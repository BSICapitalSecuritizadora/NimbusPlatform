<?php

namespace App\Services\Nimbus\Migration;

use App\Models\Nimbus\MigrationRun;
use App\Models\Nimbus\MigrationRunItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Migration control store — the ONLY writable interface during dry-run.
 * Source and target connections are read-only; control connection is writable.
 */
class MigrationControlStore
{
    public function __construct(
        private readonly string $connection = '',
    ) {}

    private function controlConnection(): string
    {
        if ($this->connection !== '') {
            return $this->connection;
        }

        return config('nimbus.migration.control_connection') ?: config('database.default');
    }

    public function createRun(array $attributes): MigrationRun
    {
        $conn = $this->controlConnection();
        // Use DB connection explicitly to ensure control DB is used
        // Eloquent will use default; for tests default == control, so okay.
        // For multi-connection, we set connection on model dynamically.
        $run = new MigrationRun($attributes);
        if ($conn !== config('database.default')) {
            $run->setConnection($conn);
        }
        $run->save();

        return $run;
    }

    public function findRunByUuid(string $uuid): ?MigrationRun
    {
        $conn = $this->controlConnection();
        $q = MigrationRun::query();
        if ($conn !== config('database.default')) {
            $q = (new MigrationRun)->setConnection($conn)->newQuery();
        }

        return $q->where('run_uuid', $uuid)->first();
    }

    public function upsertRunItem(array $attributes): MigrationRunItem
    {
        $conn = $this->controlConnection();
        // Ensure model uses control connection
        $item = new MigrationRunItem;
        if ($conn !== config('database.default')) {
            $item->setConnection($conn);
        }
        // Use query builder for control connection
        $query = DB::connection($conn)->table('nimbus_migration_run_items');
        $unique = [
            'migration_run_id' => $attributes['migration_run_id'],
            'source_system' => $attributes['source_system'] ?? 'nimbusdocs',
            'source_entity' => $attributes['source_entity'],
            'source_id' => $attributes['source_id'],
            'target_entity' => $attributes['target_entity'],
            'target_role' => $attributes['target_role'],
        ];
        $existing = $query->where($unique)->first();
        if ($existing) {
            $query->where('id', $existing->id)->update([
                'source_fingerprint' => $attributes['source_fingerprint'] ?? $existing->source_fingerprint,
                'status' => $attributes['status'],
                'disposition' => $attributes['disposition'] ?? $existing->disposition,
                'error_code' => $attributes['error_code'] ?? $existing->error_code,
                'metadata' => isset($attributes['metadata']) ? json_encode($attributes['metadata']) : $existing->metadata,
                'legacy_sha256' => $attributes['legacy_sha256'] ?? $existing->legacy_sha256,
                'actual_source_sha256' => $attributes['actual_source_sha256'] ?? $existing->actual_source_sha256,
                'target_sha256' => $attributes['target_sha256'] ?? $existing->target_sha256,
                'target_id' => $attributes['target_id'] ?? $existing->target_id,
                'updated_at' => now(),
            ]);
            $fresh = $query->where('id', $existing->id)->first();
            $model = new MigrationRunItem((array) $fresh);
            $model->exists = true;

            return $model;
        }
        $id = $query->insertGetId(array_merge($unique, [
            'source_fingerprint' => $attributes['source_fingerprint'] ?? null,
            'status' => $attributes['status'],
            'disposition' => $attributes['disposition'] ?? null,
            'error_code' => $attributes['error_code'] ?? null,
            'metadata' => isset($attributes['metadata']) ? json_encode($attributes['metadata']) : null,
            'legacy_sha256' => $attributes['legacy_sha256'] ?? null,
            'actual_source_sha256' => $attributes['actual_source_sha256'] ?? null,
            'target_sha256' => $attributes['target_sha256'] ?? null,
            'target_id' => $attributes['target_id'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        $fresh = $query->where('id', $id)->first();
        $model = new MigrationRunItem((array) $fresh);
        $model->exists = true;

        return $model;
    }

    public function runItemsForRun(int $runId): Collection
    {
        $conn = $this->controlConnection();

        return DB::connection($conn)->table('nimbus_migration_run_items')->where('migration_run_id', $runId)->get();
    }
}
