<?php

namespace App\Models;

use App\Domain\PuCalculator\Enums\PuCurveRole;
use Database\Factories\EmissionPuDailyCurveFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class EmissionPuDailyCurve extends Model
{
    /** @use HasFactory<EmissionPuDailyCurveFactory> */
    use HasFactory;

    /**
     * Linha de candidate persistida é artefato imutável: nem review nem qualquer
     * outro fluxo pode alterá-la ou apagá-la. Linhas operacionais -- inclusive as
     * legadas sem `curve_version_id` -- mantêm exatamente o lifecycle anterior.
     */
    protected static function booted(): void
    {
        $assertNotCandidate = function (self $row): void {
            if ($row->belongsToCandidateVersion()) {
                throw new LogicException('Persisted PU candidate rows are immutable.');
            }
        };

        static::updating($assertNotCandidate);
        static::deleting($assertNotCandidate);
    }

    protected $fillable = [
        'emission_id',
        'curve_version_id',
        'curve_date',
        'calculation_version',
        'is_business_day',
        'unit_base_value',
        'unit_corrected_value',
        'factor_di',
        'factor_di_accumulated',
        'factor_spread',
        'factor_spread_di',
        'interest_real_unit_value',
        'updated_unit_value',
        'amortization_ratio',
        'amortization_unit_value',
        'amortization_value',
        'residual_unit_value',
        'quantity',
        'total_value',
        'interest_payment_unit_value',
        'interest_payment_value',
        'payment_total_unit_value',
        'payment_total_value',
        'dup_correction',
        'dut_correction',
        'dup_interest',
        'dut_interest',
        'index_rate_date',
        'index_rate_value',
        'event_original_date',
        'event_effective_date',
        'calculation_memory',
    ];

    protected function casts(): array
    {
        return [
            'curve_date' => 'date',
            'calculation_version' => 'string',
            'is_business_day' => 'boolean',
            'unit_base_value' => 'decimal:16',
            'unit_corrected_value' => 'decimal:16',
            'factor_di' => 'decimal:16',
            'factor_di_accumulated' => 'decimal:16',
            'factor_spread' => 'decimal:16',
            'factor_spread_di' => 'decimal:16',
            'interest_real_unit_value' => 'decimal:16',
            'updated_unit_value' => 'decimal:16',
            'amortization_ratio' => 'decimal:16',
            'amortization_unit_value' => 'decimal:16',
            'amortization_value' => 'decimal:16',
            'residual_unit_value' => 'decimal:16',
            'quantity' => 'decimal:4',
            'total_value' => 'decimal:16',
            'interest_payment_unit_value' => 'decimal:16',
            'interest_payment_value' => 'decimal:16',
            'payment_total_unit_value' => 'decimal:16',
            'payment_total_value' => 'decimal:16',
            'dup_correction' => 'integer',
            'dut_correction' => 'integer',
            'dup_interest' => 'integer',
            'dut_interest' => 'integer',
            'index_rate_date' => 'date',
            'index_rate_value' => 'decimal:8',
            'event_original_date' => 'date',
            'event_effective_date' => 'date',
            'calculation_memory' => 'array',
        ];
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function curveVersion(): BelongsTo
    {
        return $this->belongsTo(EmissionPuCurveVersion::class, 'curve_version_id');
    }

    /**
     * Linha sem vínculo é sempre legada/operacional: o writer de candidate exige
     * `curve_version_id`, então a ausência da FK nunca pode significar candidate.
     */
    public function belongsToCandidateVersion(): bool
    {
        if ($this->curve_version_id === null) {
            return false;
        }

        $loaded = $this->relationLoaded('curveVersion') ? $this->getRelation('curveVersion') : null;

        if ($loaded instanceof EmissionPuCurveVersion) {
            return $loaded->isCandidate();
        }

        return $this->curveVersion()->candidate()->exists();
    }

    /**
     * Linha sem vínculo é linha operacional legada. Toda linha de candidate
     * governada carrega `curve_version_id` e nunca cai neste fallback.
     *
     * @param  Builder<EmissionPuDailyCurve>  $query
     * @return Builder<EmissionPuDailyCurve>
     */
    public function scopeOperational(Builder $query): Builder
    {
        return $query->where(function (Builder $operational): void {
            $operational
                ->whereNull('curve_version_id')
                ->orWhereHas('curveVersion', fn (Builder $version): Builder => $version
                    ->where('curve_role', PuCurveRole::Operational->value));
        });
    }

    /**
     * @param  Builder<EmissionPuDailyCurve>  $query
     * @return Builder<EmissionPuDailyCurve>
     */
    public function scopeCandidate(Builder $query): Builder
    {
        return $query->whereHas('curveVersion', fn (Builder $version): Builder => $version
            ->where('curve_role', PuCurveRole::Candidate->value));
    }

    /**
     * @param  Builder<EmissionPuDailyCurve>  $query
     * @return Builder<EmissionPuDailyCurve>
     */
    public function scopeForCalculationVersion(Builder $query, ?string $calculationVersion): Builder
    {
        if ($calculationVersion === null) {
            return $query;
        }

        return $query->where('calculation_version', $calculationVersion);
    }

    public static function latestCalculationVersionForEmission(int $emissionId): ?string
    {
        return static::query()
            ->where('emission_id', $emissionId)
            ->operational()
            ->orderByDesc('id')
            ->value('calculation_version');
    }
}
