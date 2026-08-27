<?php

namespace App\Models;

use Database\Factories\IndexRateSourceGovernanceReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class IndexRateSourceGovernanceReview extends Model
{
    /** @use HasFactory<IndexRateSourceGovernanceReviewFactory> */
    use HasFactory, LogsActivity;

    public const STATUS_APPROVED = 'approved';

    protected $fillable = [
        'source_code',
        'report_checksum',
        'artifact_disk',
        'artifact_path',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
