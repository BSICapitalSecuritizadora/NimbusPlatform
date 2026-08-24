<?php

namespace App\Models;

use Database\Factories\BusinessCalendarStagingBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessCalendarStagingBatch extends Model
{
    /** @use HasFactory<BusinessCalendarStagingBatchFactory> */
    use HasFactory;

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'batch_uuid',
        'calendar_code',
        'year',
        'source',
        'source_is_official',
        'source_document',
        'source_revision',
        'checksum',
        'status',
        'records_staged',
        'staged_by',
        'staged_at',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'source_is_official' => 'boolean',
            'records_staged' => 'integer',
            'staged_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(BusinessCalendar::class, 'calendar_code', 'code');
    }

    public function dates(): HasMany
    {
        return $this->hasMany(BusinessCalendarStagedDate::class);
    }

    public function stagedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staged_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
