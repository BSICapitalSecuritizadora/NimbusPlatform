<?php

namespace App\Models;

use App\Concerns\DerivesStoredFileMetadata;
use App\Services\DocumentStorageService;
use Database\Factories\MeasurementAssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
            if (blank($asset->filename) && filled($asset->storage_path)) {
                $asset->filename = basename((string) $asset->storage_path);
            }

            if (blank($asset->uploaded_at)) {
                $asset->uploaded_at = now();
            }
        });
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
}
