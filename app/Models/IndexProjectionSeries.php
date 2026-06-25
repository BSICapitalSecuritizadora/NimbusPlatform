<?php

namespace App\Models;

use App\Domain\PuCalculator\Enums\IndexProjectionSeriesStatus;
use App\Domain\PuCalculator\Enums\PuIndexer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IndexProjectionSeries extends Model
{
    /** @use HasFactory<\Database\Factories\IndexProjectionSeriesFactory> */
    use HasFactory;

    protected $table = 'index_projection_series';

    protected $fillable = [
        'indexer',
        'name',
        'status',
        'projection_source',
        'projection_policy',
        'version',
        'reference_date',
        'description',
        'rejection_reason',
        'obsolete_reason',
        'imported_by',
        'approved_by',
        'rejected_by',
        'imported_at',
        'approved_at',
        'rejected_at',
        'obsoleted_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => IndexProjectionSeriesStatus::class,
            'reference_date' => 'date',
            'imported_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'obsoleted_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<IndexRate, $this>
     */
    public function rates(): HasMany
    {
        return $this->hasMany(IndexRate::class, 'index_projection_series_id');
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * @param  Builder<IndexProjectionSeries>  $query
     * @return Builder<IndexProjectionSeries>
     */
    public function scopeForIndexer(Builder $query, PuIndexer|string $indexer): Builder
    {
        return $query->where('indexer', $indexer instanceof PuIndexer ? $indexer->value : $indexer);
    }

    /**
     * @param  Builder<IndexProjectionSeries>  $query
     * @return Builder<IndexProjectionSeries>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', IndexProjectionSeriesStatus::Approved->value);
    }

    public function isApproved(): bool
    {
        return $this->status === IndexProjectionSeriesStatus::Approved;
    }
}
