<?php

namespace App\Models\Nimbus;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MigrationRunItem extends Model
{
    protected $table = 'nimbus_migration_run_items';

    protected $guarded = ['id'];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(MigrationRun::class, 'migration_run_id');
    }
}
