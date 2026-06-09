<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeasurementAsset extends Model
{
    /** @use HasFactory<\Database\Factories\MeasurementAssetFactory> */
    use HasFactory;

    protected $fillable = [
        'measurement_id',
        'plan_set_id',
        'plan_line_id',
        'filename',
        'storage_path',
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
}
