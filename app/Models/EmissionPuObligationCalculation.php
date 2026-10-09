<?php

namespace App\Models;

use App\Domain\PuCalculator\Enums\PuObligationComponent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Valor esperado de uma obrigação calculado por uma curva oficial (Fase 5).
 *
 * Imutável: o que a versão v1 calculou continua explicável depois que a v2
 * passar a ser a oficial. A única mudança permitida é a marcação de substituição
 * (quando, por qual cálculo, por quê), uma vez só. Nunca é apagado.
 *
 * `status`: `complete` quando todo componente tem valor (o total é a soma
 * canônica em 2 casas); `incomplete` quando algum componente não é suportado --
 * o total fica nulo e nada é conciliado contra ele.
 */
class EmissionPuObligationCalculation extends Model
{
    public const STATUS_COMPLETE = 'complete';

    public const STATUS_INCOMPLETE = 'incomplete';

    /** @var list<string> */
    private const SUPERSESSION_FIELDS = ['superseded_at', 'superseded_by_calculation_id', 'supersession_reason', 'updated_at'];

    protected $fillable = [
        'obligation_id',
        'emission_id',
        'curve_version_id',
        'calculation_version',
        'status',
        'due_date',
        'total_amount',
        'components_fingerprint',
        'calculated_at',
        'superseded_at',
        'superseded_by_calculation_id',
        'supersession_reason',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $calculation): void {
            foreach (array_keys($calculation->getDirty()) as $field) {
                if (! in_array($field, self::SUPERSESSION_FIELDS, true)) {
                    throw new LogicException('An expected PU obligation calculation is immutable.');
                }

                if ($field !== 'updated_at' && $calculation->getRawOriginal($field) !== null) {
                    throw new LogicException('A PU obligation calculation is superseded only once.');
                }
            }
        });

        static::deleting(function (): void {
            throw new LogicException('Expected PU obligation calculations are never deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'total_amount' => 'decimal:2',
            'calculated_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(EmissionPuObligation::class, 'obligation_id');
    }

    public function curveVersion(): BelongsTo
    {
        return $this->belongsTo(EmissionPuCurveVersion::class, 'curve_version_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(EmissionPuObligationComponent::class, 'calculation_id');
    }

    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETE;
    }

    public function isCurrent(): bool
    {
        return $this->superseded_at === null;
    }

    /**
     * Valor esperado de um componente (soma dos donos), ou nulo quando não há.
     */
    public function componentAmount(PuObligationComponent $component): ?string
    {
        $amounts = $this->components
            ->filter(fn (EmissionPuObligationComponent $row): bool => $row->component === $component && $row->amount !== null)
            ->map(fn (EmissionPuObligationComponent $row): string => (string) $row->amount);

        return $amounts->isEmpty() ? null : $amounts->reduce(fn (string $total, string $amount): string => bcadd($total, $amount, 2), '0.00');
    }
}
