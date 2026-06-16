<?php

namespace App\Models;

use App\Domain\PuCalculator\Enums\PuIndexer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
    ];

    protected function casts(): array
    {
        return [
            'rate_date' => 'date',
            'rate_value' => 'decimal:8',
        ];
    }

    public function scopeForIndexer($query, PuIndexer|string $indexer)
    {
        $indexerValue = $indexer instanceof PuIndexer ? $indexer->value : $indexer;

        return $query->where('indexer', $indexerValue);
    }
}
