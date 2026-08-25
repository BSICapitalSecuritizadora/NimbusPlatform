<?php

namespace App\Models;

use App\Concerns\DerivesStoredFileMetadata;
use App\Exceptions\MeasurementWorkflowException;
use App\Services\DocumentStorageService;
use Database\Factories\MeasurementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Measurement extends Model
{
    /** @use HasFactory<MeasurementFactory> */
    use DerivesStoredFileMetadata, HasFactory, LogsActivity;

    protected $attributes = [
        'storage_disk' => DocumentStorageService::DEFAULT_PRIVATE_DISK,
    ];

    public const STATUS_OPTIONS = [
        'pending' => 'Aguardando Análise',
        'in_review' => 'Em Análise',
        'paused' => 'Pausada',
        'rejected' => 'Recusada',
        'approved' => 'Documentação financeira completa',
        'awaiting_payment' => 'Etapa Pagamento',
        'awaiting_receipt' => 'Finalização — aguardando comprovante',
        'finalized' => 'Finalizada',
    ];

    protected $fillable = [
        'operation_id',
        'plan_set_id',
        'reference_month',
        'filename',
        'storage_path',
        'storage_disk',
        'sha256',
        'file_size',
        'mime_type',
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
            if ($measurement->exists && $measurement->isDirty('operation_id')) {
                throw new MeasurementWorkflowException('A operação de uma medição não pode ser alterada após sua criação.', [
                    'measurement_id' => $measurement->getKey(),
                    'original_operation_id' => $measurement->getOriginal('operation_id'),
                    'attempted_operation_id' => $measurement->operation_id,
                ]);
            }

            if ($measurement->exists
                && $measurement->hasApprovedEngineering()
                && $measurement->isDirty([
                    'reference_month',
                    'filename',
                    'storage_path',
                    'storage_disk',
                    'sha256',
                    'file_size',
                    'mime_type',
                ])) {
                throw new MeasurementWorkflowException('Os dados aprovados pela Engenharia estão bloqueados. Devolva a medição à Engenharia para corrigi-los.', [
                    'measurement_id' => $measurement->getKey(),
                ]);
            }

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
            'workflow_revision' => 'integer',
            'engineering_snapshot' => 'array',
            'file_size' => 'integer',
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

    public function hasApprovedEngineering(): bool
    {
        return $this->reviews()
            ->where('stage', 1)
            ->where('status', 'approved')
            ->exists();
    }

    public function getResolvedStorageDiskAttribute(): string
    {
        return $this->storage_disk ?: 'public';
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereHas('operation', fn (Builder $operations): Builder => $operations->visibleTo($user));
    }

    protected function storedFilePathColumn(): string
    {
        return 'storage_path';
    }

    protected function storedFileMimeColumn(): string
    {
        return 'mime_type';
    }

    protected function storedFileSizeColumn(): string
    {
        return 'file_size';
    }

    protected function storedFileChecksumColumn(): ?string
    {
        return 'sha256';
    }

    protected function storedFileNameColumn(): ?string
    {
        return 'filename';
    }

    protected function storedFileMetadataDisk(): string
    {
        return $this->resolved_storage_disk;
    }
}
