<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Payment extends Model
{
    use LogsActivity;

    /**
     * Valor calculado pela curva oficial homologada. Nulo é valor informado
     * (planilha ou cadastro manual).
     */
    public const SOURCE_OFFICIAL_CURVE = 'official_curve';

    /** @var list<string> */
    public const VALUE_FIELDS = [
        'premium_value',
        'interest_value',
        'amortization_value',
        'extra_amortization_value',
    ];

    protected $fillable = [
        'emission_id',
        'payment_date',
        'premium_value',
        'interest_value',
        'amortization_value',
        'extra_amortization_value',
        'value_source',
        'expected_premium_value',
        'expected_interest_value',
        'expected_amortization_value',
        'expected_extra_amortization_value',
        'pu_curve_version_id',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'premium_value' => 'decimal:2',
            'interest_value' => 'decimal:2',
            'amortization_value' => 'decimal:2',
            'extra_amortization_value' => 'decimal:2',
            'expected_premium_value' => 'decimal:2',
            'expected_interest_value' => 'decimal:2',
            'expected_amortization_value' => 'decimal:2',
            'expected_extra_amortization_value' => 'decimal:2',
            'calculated_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function puCurveVersion(): BelongsTo
    {
        return $this->belongsTo(EmissionPuCurveVersion::class, 'pu_curve_version_id');
    }

    public function isCalculatedByOfficialCurve(): bool
    {
        return $this->value_source === self::SOURCE_OFFICIAL_CURVE;
    }

    /**
     * Soma do valor previsto guardado quando a curva passou a calcular a linha.
     */
    public function expectedTotal(): ?string
    {
        if ($this->expected_interest_value === null && $this->expected_amortization_value === null
            && $this->expected_premium_value === null && $this->expected_extra_amortization_value === null) {
            return null;
        }

        return collect(['expected_premium_value', 'expected_interest_value', 'expected_amortization_value', 'expected_extra_amortization_value'])
            ->reduce(fn (string $total, string $field): string => bcadd($total, (string) ($this->{$field} ?? '0'), 2), '0');
    }
}
