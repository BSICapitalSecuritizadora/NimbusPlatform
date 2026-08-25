<?php

namespace App\Models\Nimbus;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MigrationMap extends Model
{
    protected $table = 'nimbus_migration_maps';

    protected $guarded = ['id'];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function firstRun(): BelongsTo
    {
        return $this->belongsTo(MigrationRun::class, 'first_migration_run_id');
    }

    public function lastVerifiedRun(): BelongsTo
    {
        return $this->belongsTo(MigrationRun::class, 'last_verified_run_id');
    }
}
