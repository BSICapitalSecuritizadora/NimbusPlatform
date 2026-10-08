<?php

namespace App\Models;

use App\Domain\PuCalculator\Enums\PuObligationCalculationState;
use App\Domain\PuCalculator\Enums\PuObligationLifecycle;
use App\Domain\PuCalculator\Enums\PuObligationType;
use App\Domain\PuCalculator\Enums\PuReconciliationStatus;
use App\Domain\PuCalculator\Enums\PuSettlementState;
use App\Domain\PuCalculator\Enums\PuSettlementStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * Obrigação financeira econômica de uma emissão (Fase 5).
 *
 * É o que o contrato diz que é devido -- não o valor calculado (que fica nos
 * cálculos versionados) nem o liquidado (que fica no livro de liquidações). A
 * identidade (emissão, natureza, data contratual, sequência) é imutável e única
 * no banco: reprocessar a curva recalcula o esperado desta obrigação, nunca cria
 * outra. Também nunca é apagada -- a que sai do cronograma oficial fica superada.
 *
 * `settlement_state`, `reconciliation_status` e `calculation_state` são
 * derivados (refeitos pelo serviço de obrigações a partir dos fatos), guardados
 * para consulta.
 */
class EmissionPuObligation extends Model
{
    /** @var list<string> */
    private const IDENTITY_FIELDS = ['emission_id', 'obligation_type', 'contractual_date', 'sequence'];

    protected $fillable = [
        'emission_id',
        'obligation_type',
        'contractual_date',
        'sequence',
        'due_date',
        'lifecycle_status',
        'superseded_at',
        'supersession_reason',
        'superseded_by_obligation_id',
        'source_event_ids',
        'payment_id',
        'current_calculation_id',
        'calculation_state',
        'calculation_state_reason',
        'settlement_state',
        'reconciliation_status',
        'latest_reconciliation_id',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $obligation): void {
            foreach (self::IDENTITY_FIELDS as $field) {
                if ($obligation->isDirty($field)) {
                    throw new LogicException('The economic identity of a PU obligation is immutable.');
                }
            }
        });

        static::deleting(function (): void {
            throw new LogicException('PU obligations are never deleted: supersede them instead.');
        });
    }

    protected function casts(): array
    {
        return [
            'obligation_type' => PuObligationType::class,
            'contractual_date' => 'date',
            'sequence' => 'integer',
            'due_date' => 'date',
            'lifecycle_status' => PuObligationLifecycle::class,
            'superseded_at' => 'datetime',
            'source_event_ids' => 'array',
            'calculation_state' => PuObligationCalculationState::class,
            'settlement_state' => PuSettlementState::class,
            'reconciliation_status' => PuReconciliationStatus::class,
        ];
    }

    public static function identityKey(PuObligationType|string $type, string $contractualDate, int $sequence): string
    {
        $type = $type instanceof PuObligationType ? $type->value : $type;

        return sprintf('%s|%s|%d', $type, $contractualDate, $sequence);
    }

    public function key(): string
    {
        return self::identityKey(
            $this->obligation_type,
            CarbonImmutable::instance($this->contractual_date)->toDateString(),
            (int) $this->sequence,
        );
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_obligation_id');
    }

    public function currentCalculation(): BelongsTo
    {
        return $this->belongsTo(EmissionPuObligationCalculation::class, 'current_calculation_id');
    }

    public function calculations(): HasMany
    {
        return $this->hasMany(EmissionPuObligationCalculation::class, 'obligation_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(EmissionPuSettlement::class, 'obligation_id');
    }

    public function activeSettlement(): HasOne
    {
        return $this->hasOne(EmissionPuSettlement::class, 'obligation_id')
            ->where('status', PuSettlementStatus::Active->value);
    }

    public function conflicts(): HasMany
    {
        return $this->hasMany(EmissionPuSettlementConflict::class, 'obligation_id');
    }

    public function reconciliations(): HasMany
    {
        return $this->hasMany(EmissionPuReconciliation::class, 'obligation_id');
    }

    public function latestReconciliation(): BelongsTo
    {
        return $this->belongsTo(EmissionPuReconciliation::class, 'latest_reconciliation_id');
    }

    /**
     * @param  Builder<EmissionPuObligation>  $query
     * @return Builder<EmissionPuObligation>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('lifecycle_status', PuObligationLifecycle::Active->value);
    }

    public function isActive(): bool
    {
        return $this->lifecycle_status === PuObligationLifecycle::Active;
    }
}
