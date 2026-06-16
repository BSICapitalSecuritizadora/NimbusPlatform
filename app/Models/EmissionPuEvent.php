<?php

namespace App\Models;

use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmissionPuEvent extends Model
{
    /** @use HasFactory<\Database\Factories\EmissionPuEventFactory> */
    use HasFactory;

    protected $fillable = [
        'emission_id',
        'event_type',
        'original_date',
        'effective_date',
        'amortization_type',
        'amortization_value',
        'sequence',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'original_date' => 'date',
            'effective_date' => 'date',
            'amortization_value' => 'decimal:16',
            'sequence' => 'integer',
        ];
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function getEventTypeEnumAttribute(): PuEventType
    {
        return PuEventType::from((string) $this->event_type);
    }

    public function getAmortizationTypeEnumAttribute(): PuAmortizationType
    {
        return PuAmortizationType::from((string) $this->amortization_type);
    }
}
