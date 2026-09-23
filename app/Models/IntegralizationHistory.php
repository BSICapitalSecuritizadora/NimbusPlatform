<?php

namespace App\Models;

use App\Actions\Emissions\RecordIntegralizationHistory;
use App\Enums\IntegralizationSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Contracts\Activity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class IntegralizationHistory extends Model
{
    use LogsActivity;

    /**
     * Canal da gravação em andamento, preenchido por {@see RecordIntegralizationHistory}.
     * Não é atributo: só existe para o Activitylog registrar a origem do evento.
     */
    public ?IntegralizationSource $recordedThrough = null;

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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function tapActivity(Activity $activity): void
    {
        if ($this->recordedThrough === null) {
            return;
        }

        $properties = $activity->properties ?? collect();

        $activity->properties = $properties->put('source', $this->recordedThrough->value);
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
