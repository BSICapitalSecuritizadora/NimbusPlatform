<?php

namespace App\Models;

use App\Exceptions\MeasurementWorkflowException;
use Database\Factories\MeasurementReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class MeasurementReview extends Model
{
    /** @use HasFactory<MeasurementReviewFactory> */
    use HasFactory, LogsActivity;

    /**
     * A análise que ainda esperava decisão quando outra revisão passou a valer
     * no lugar desta medição: encerrada pela substituição, sem decisão da
     * etapa -- nem aprovada, nem recusada.
     */
    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_OPTIONS = [
        'pending' => 'Pendente',
        'approved' => 'Aprovada',
        'rejected' => 'Recusada',
        self::STATUS_SUPERSEDED => 'Encerrada pela revisão',
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

    protected static function booted(): void
    {
        // A etapa encerrada pela revisão que passou a valer é histórico: nenhum
        // caminho -- reabertura, aprovação, pausa -- a devolve ao fluxo.
        static::updating(function (self $review): void {
            if ($review->getRawOriginal('status') === self::STATUS_SUPERSEDED) {
                throw new MeasurementWorkflowException('A etapa desta medição foi encerrada pela revisão que passou a valer e não muda mais.', [
                    'measurement_review_id' => $review->getKey(),
                    'measurement_id' => $review->measurement_id,
                ]);
            }
        });
    }

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
            ->dontLogEmptyChanges();
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

    public function isSupersededByRevision(): bool
    {
        return $this->status === self::STATUS_SUPERSEDED;
    }
}
