<?php

namespace App\Models\Nimbus;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubmissionStatusHistory extends Model
{
    protected $table = 'nimbus_submission_status_histories';

    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class, 'nimbus_submission_id');
    }

    /**
     * Append-only: prevent updates/deletes from application layer.
     * Database-level protections are via policy (no UI) + tests.
     */
    protected static function booted(): void
    {
        static::updating(function (): bool {
            return false;
        });

        static::deleting(function (): bool {
            return false;
        });
    }
}
