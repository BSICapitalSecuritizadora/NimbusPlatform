<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Measurement extends Model
{
    /** @use HasFactory<\Database\Factories\MeasurementFactory> */
    use HasFactory, LogsActivity;

    public const STATUS_OPTIONS = [
        'pending' => 'Aguardando Análise',
        'in_review' => 'Em Análise',
        'paused' => 'Pausada',
        'rejected' => 'Recusada',
        'approved' => 'Aprovada',
        'awaiting_payment' => 'Aguardando Pagamento',
        'awaiting_receipt' => 'Aguardando Comprovante',
        'finalized' => 'Finalizada',
    ];

    protected $fillable = [
        'operation_id',
        'plan_set_id',
        'reference_month',
        'filename',
        'storage_path',
        'notes',
        'status',
        'current_stage',
        'uploaded_by',
        'uploaded_at',
        'analyzed_by',
        'analyzed_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $measurement): void {
            if (blank($measurement->filename) && filled($measurement->storage_path)) {
                $measurement->filename = basename((string) $measurement->storage_path);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'reference_month' => 'date',
            'current_stage' => 'integer',
            'uploaded_at' => 'datetime',
            'analyzed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_OPTIONS[$this->status] ?? $this->status;
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function planSet(): BelongsTo
    {
        return $this->belongsTo(MeasurementPlanSet::class, 'plan_set_id');
    }

    public function uploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function analyzedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'analyzed_by');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(MeasurementAsset::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(MeasurementReview::class);
    }

    public function pauses(): HasMany
    {
        return $this->hasMany(MeasurementPause::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(MeasurementPayment::class);
    }

    public function reviewForStage(int $stage): ?MeasurementReview
    {
        return $this->reviews->firstWhere('stage', $stage);
    }
}
