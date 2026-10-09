<?php

namespace App\Models;

use App\Domain\PuCalculator\Enums\PuObligationComponent;
use App\Domain\PuCalculator\Enums\PuObligationComponentOwner;
use App\Domain\PuCalculator\Enums\PuObligationComponentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Um componente do valor esperado (juros ordinários, amortização ordinária,
 * prêmio...), com quem responde por ele e de onde veio. Imutável como o cálculo
 * a que pertence.
 */
class EmissionPuObligationComponent extends Model
{
    protected $fillable = [
        'calculation_id',
        'component',
        'owner',
        'status',
        'amount',
        'unit_amount',
        'quantity',
        'reason',
        'source',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Expected PU obligation components are immutable.');
        });

        static::deleting(function (): void {
            throw new LogicException('Expected PU obligation components are never deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'component' => PuObligationComponent::class,
            'owner' => PuObligationComponentOwner::class,
            'status' => PuObligationComponentStatus::class,
            'amount' => 'decimal:2',
            'unit_amount' => 'decimal:16',
            'quantity' => 'decimal:4',
            'source' => 'array',
        ];
    }

    public function calculation(): BelongsTo
    {
        return $this->belongsTo(EmissionPuObligationCalculation::class, 'calculation_id');
    }
}
