<?php

namespace App\Models;

use App\Domain\PuCalculator\Enums\PuIndexer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmissionPuParameter extends Model
{
    /** @use HasFactory<\Database\Factories\EmissionPuParameterFactory> */
    use HasFactory;

    protected $fillable = [
        'emission_id',
        'curve_start_date',
        'curve_end_date',
        'initial_unit_value',
        'spread_rate',
        'indexer',
        'business_day_basis',
        'calendar_code',
        'legacy_projection_enabled',
    ];

    protected function casts(): array
    {
        return [
            'curve_start_date' => 'date',
            'curve_end_date' => 'date',
            'initial_unit_value' => 'decimal:16',
            'spread_rate' => 'decimal:8',
            'business_day_basis' => 'integer',
            'legacy_projection_enabled' => 'boolean',
        ];
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function getIndexerEnumAttribute(): PuIndexer
    {
        return PuIndexer::from((string) $this->indexer);
    }
}
