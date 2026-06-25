<?php

namespace App\Models;

use App\Domain\PuCalculator\Enums\PuIndexer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IndexRate extends Model
{
    /** @use HasFactory<\Database\Factories\IndexRateFactory> */
    use HasFactory;

    protected $fillable = [
        'indexer',
        'rate_date',
        'rate_value',
        'source',
        'source_reference',
        'is_projected',
        'projection_source',
        'projection_reference_date',
        'projection_policy',
        'index_projection_series_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'rate_date' => 'date',
            'rate_value' => 'decimal:8',
            'is_projected' => 'boolean',
            'projection_reference_date' => 'date',
        ];
    }

    public function scopeForIndexer($query, PuIndexer|string $indexer)
    {
        $indexerValue = $indexer instanceof PuIndexer ? $indexer->value : $indexer;

        return $query->where('indexer', $indexerValue);
    }

    /**
     * Série projetada (maker/checker) à qual esta linha projetada pertence. Linhas publicadas
     * mantêm a relação nula.
     *
     * @return BelongsTo<IndexProjectionSeries, $this>
     */
    public function projectionSeries(): BelongsTo
    {
        return $this->belongsTo(IndexProjectionSeries::class, 'index_projection_series_id');
    }

    /**
     * Número-índice projetado (política de projeção) versus publicado. A marcação explícita em
     * `is_projected` tem precedência; mantém-se compatibilidade com o legado `forward_projection`.
     */
    public function isProjectedRate(): bool
    {
        return (bool) $this->is_projected || (string) $this->source_reference === 'forward_projection';
    }
}
