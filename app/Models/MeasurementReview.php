<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class MeasurementReview extends Model
{
    /** @use HasFactory<\Database\Factories\MeasurementReviewFactory> */
    use HasFactory, LogsActivity;

    public const STATUS_OPTIONS = [
        'pending' => 'Pendente',
        'approved' => 'Aprovada',
        'rejected' => 'Recusada',
    ];

    protected $fillable = [
        'measurement_id',
        'stage',
        'reviewer_user_id',
        'status',
        'notes',
        'reviewed_at',
        'paused_at',
        'paused_by',
        'pause_reason',
        'paused_operation_status',
    ];

    protected function casts(): array
    {
        return [
            'stage' => 'integer',
            'reviewed_at' => 'datetime',
            'paused_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function measurement(): BelongsTo
    {
        return $this->belongsTo(Measurement::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    public function pausedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paused_by');
    }

    public function isPaused(): bool
    {
        return $this->paused_at !== null;
    }
}
