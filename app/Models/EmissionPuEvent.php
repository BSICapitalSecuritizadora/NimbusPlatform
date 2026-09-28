<?php

namespace App\Models;

use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventDateChangeReason;
use App\Domain\PuCalculator\Enums\PuEventType;
use Database\Factories\EmissionPuEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmissionPuEvent extends Model
{
    /** @use HasFactory<EmissionPuEventFactory> */
    use HasFactory;

    protected $fillable = [
        'emission_id',
        'event_type',
        'original_date',
        'effective_date',
        'effective_date_reason',
        'effective_date_justification',
        'effective_date_evidence_reference',
        'effective_date_evidence_excerpt',
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
            'effective_date_reason' => PuEventDateChangeReason::class,
            'amortization_value' => 'decimal:16',
            'sequence' => 'integer',
        ];
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    /**
     * A data efetiva saiu da data original do contrato e ninguém registrou por quê.
     */
    public function hasUnjustifiedDateChange(): bool
    {
        return $this->original_date !== null
            && $this->effective_date !== null
            && ! $this->original_date->isSameDay($this->effective_date)
            && $this->effective_date_reason === null;
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
