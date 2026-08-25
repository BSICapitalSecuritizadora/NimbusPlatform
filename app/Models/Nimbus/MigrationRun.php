<?php

namespace App\Models\Nimbus;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MigrationRun extends Model
{
    protected $table = 'nimbus_migration_runs';

    protected $guarded = ['id'];

    protected $casts = [
        'source_snapshot_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'dry_run' => 'boolean',
        'summary' => 'array',
    ];

    public function maps(): HasMany
    {
        return $this->hasMany(MigrationMap::class, 'migration_run_id');
    }
}
