<?php

namespace App\Models;

use App\Enums\MeasurementRevisionDifferenceType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A diferença que uma revisão de medição introduz em um empreendimento,
 * calculada dos snapshots congelados da Engenharia (da revisão substituída e
 * da revisão) e dos pagamentos que a família já tinha.
 *
 * É evidência: não muda (só `superseded_at`, uma vez, quando a revisão volta à
 * Engenharia e é reaprovada) e nunca se exclui. O conjunto corrente de cada
 * revisão é o de `superseded_at` nulo -- um por empreendimento, garantido pelo
 * banco.
 */
class MeasurementRevisionDifference extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'measurement_id' => 'integer',
            'previous_measurement_id' => 'integer',
            'revision_family_id' => 'integer',
            'operation_id' => 'integer',
            'plan_set_id' => 'integer',
            'plan_version_id' => 'integer',
            'settled_measurement_id' => 'integer',
            'computation' => 'integer',
            'previous_realized_monthly_percent' => 'decimal:2',
            'revised_realized_monthly_percent' => 'decimal:2',
            'physical_difference_percent' => 'decimal:2',
            'previous_fund_amount' => 'decimal:2',
            'revised_fund_amount' => 'decimal:2',
            'previous_approved_amount' => 'decimal:2',
            'revised_approved_amount' => 'decimal:2',
            'financial_difference_amount' => 'decimal:2',
            'difference_type' => MeasurementRevisionDifferenceType::class,
            'historical_paid_amount' => 'decimal:2',
            'settled_approved_amount' => 'decimal:2',
            'unresolved_overpayment_amount' => 'decimal:2',
            'superseded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $difference): void {
            if (array_diff(array_keys($difference->getDirty()), ['superseded_at', 'updated_at']) !== []
                || $difference->getRawOriginal('superseded_at') !== null
                || $difference->superseded_at === null) {
                throw new LogicException('A diferença registrada de uma revisão não muda: um novo cálculo grava um conjunto novo e marca o anterior como substituído.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('A diferença registrada de uma revisão é evidência e não pode ser excluída.');
        });
    }

    public function measurement(): BelongsTo
    {
        return $this->belongsTo(Measurement::class);
    }

    public function previousMeasurement(): BelongsTo
    {
        return $this->belongsTo(Measurement::class, 'previous_measurement_id');
    }

    public function settledMeasurement(): BelongsTo
    {
        return $this->belongsTo(Measurement::class, 'settled_measurement_id');
    }

    public function planSet(): BelongsTo
    {
        return $this->belongsTo(MeasurementPlanSet::class, 'plan_set_id');
    }

    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(MeasurementPlanVersion::class, 'plan_version_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('superseded_at'));
    }

    public function hasUnresolvedOverpayment(): bool
    {
        return bccomp((string) ($this->unresolved_overpayment_amount ?? '0'), '0', 2) > 0;
    }
}
