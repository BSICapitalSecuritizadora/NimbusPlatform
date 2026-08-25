<?php

namespace App\Models;

use App\Concerns\DerivesStoredFileMetadata;
use App\Exceptions\MeasurementWorkflowException;
use App\Services\DocumentStorageService;
use App\Services\MeasurementFileValidationService;
use Database\Factories\MeasurementAssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class MeasurementAsset extends Model
{
    /** @use HasFactory<MeasurementAssetFactory> */
    use DerivesStoredFileMetadata, HasFactory;

    protected $attributes = [
        'storage_disk' => DocumentStorageService::DEFAULT_PRIVATE_DISK,
    ];

    protected $fillable = [
        'measurement_id',
        'plan_set_id',
        'plan_line_id',
        'filename',
        'storage_path',
        'storage_disk',
        'sha256',
        'mime_type',
        'size',
        'uploaded_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $asset): void {
            $measurement = $asset->measurement()->first();

            if ($measurement?->hasApprovedEngineering()) {
                throw new MeasurementWorkflowException('Os arquivos aprovados pela Engenharia estão bloqueados. Devolva a medição à Engenharia para alterá-los.', [
                    'measurement_id' => $measurement->getKey(),
                    'asset_id' => $asset->getKey(),
                ]);
            }

            if ($asset->isDirty(['storage_path', 'storage_disk']) && filled($asset->storage_path)) {
                app(MeasurementFileValidationService::class)->validateAsset(
                    (string) $asset->storage_path,
                    $asset->resolved_storage_disk,
                );
            }

            if (blank($asset->filename) && filled($asset->storage_path)) {
                $asset->filename = basename((string) $asset->storage_path);
            }

            if (blank($asset->uploaded_at)) {
                $asset->uploaded_at = now();
            }
        });

        static::deleting(function (self $asset): void {
            $measurement = $asset->measurement()->first();

            if ($measurement?->hasApprovedEngineering()) {
                throw new MeasurementWorkflowException('Um arquivo aprovado pela Engenharia não pode ser removido sem devolver a medição à Engenharia.', [
                    'measurement_id' => $measurement->getKey(),
                    'asset_id' => $asset->getKey(),
                ]);
            }
        });

        static::created(fn (self $asset) => $asset->auditAssetChange('measurement_asset_created', null));
        static::updated(function (self $asset): void {
            if ($asset->wasChanged(['storage_path', 'storage_disk', 'sha256', 'plan_set_id', 'plan_line_id'])) {
                $asset->auditAssetChange('measurement_asset_replaced', $asset->getOriginal('sha256'));
            }
        });
        static::deleted(fn (self $asset) => $asset->auditAssetChange('measurement_asset_removed', $asset->sha256));
    }

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'uploaded_at' => 'datetime',
        ];
    }

    public function measurement(): BelongsTo
    {
        return $this->belongsTo(Measurement::class);
    }

    public function planSet(): BelongsTo
    {
        return $this->belongsTo(MeasurementPlanSet::class, 'plan_set_id');
    }

    public function planLine(): BelongsTo
    {
        return $this->belongsTo(MeasurementPlanLine::class, 'plan_line_id');
    }

    public function fileMigrationJournal(): MorphOne
    {
        return $this->morphOne(MeasurementFileMigration::class, 'migratable');
    }

    public function getResolvedStorageDiskAttribute(): string
    {
        return $this->storage_disk ?: 'public';
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
        return 'size';
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

    private function auditAssetChange(string $event, ?string $oldHash): void
    {
        $measurement = $this->measurement()->first();
        $activity = activity('measurement_assets')
            ->performedOn($this)
            ->withProperties([
                'asset_id' => $this->getKey(),
                'measurement_id' => $this->measurement_id,
                'operation_id' => $measurement?->operation_id,
                'plan_set_id' => $this->plan_set_id,
                'plan_line_id' => $this->plan_line_id,
                'old_sha256' => $oldHash,
                'new_sha256' => $event === 'measurement_asset_removed' ? null : $this->sha256,
                'actor_user_id' => auth()->id(),
            ]);

        if (auth()->user() instanceof User) {
            $activity->causedBy(auth()->user());
        }

        $activity->log($event);
    }
}
