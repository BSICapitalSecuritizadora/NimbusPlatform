<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegralizationHistory extends Model
{
    protected static function booted(): void
    {
        static::saving(function (self $integralizationHistory): void {
            $integralizationHistory->validateIssuedQuantityLimit();
        });

        static::saved(function (self $integralizationHistory): void {
            self::syncEmissionIntegralizedQuantity($integralizationHistory->emission_id);

            if ($integralizationHistory->wasChanged('emission_id')) {
                self::syncEmissionIntegralizedQuantity($integralizationHistory->getOriginal('emission_id'));
            }
        });

        static::deleted(function (self $integralizationHistory): void {
            self::syncEmissionIntegralizedQuantity($integralizationHistory->getOriginal('emission_id'));
        });
    }

    protected $fillable = [
        'emission_id',
        'date',
        'quantity',
        'unit_value',
        'financial_value',
        'investor_fund',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'quantity' => 'decimal:4',
            'unit_value' => 'decimal:8',
            'financial_value' => 'decimal:2',
        ];
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    private static function syncEmissionIntegralizedQuantity(int|string|null $emissionId): void
    {
        if (! filled($emissionId)) {
            return;
        }

        Emission::query()
            ->find($emissionId)
            ?->syncIntegralizedQuantityFromHistories();
    }

    private function validateIssuedQuantityLimit(): void
    {
        $emission = $this->relationLoaded('emission')
            ? $this->emission
            : Emission::query()->find($this->emission_id);

        if (! $emission) {
            return;
        }

        $emission->ensureIntegralizationQuantityWithinIssuedLimit(
            quantity: $this->quantity,
            ignoringIntegralizationHistory: $this->exists ? $this : null,
        );
    }
}
