<?php

namespace App\Models;

use App\Domain\PuCalculator\Enums\PuSettlementEntryType;
use App\Domain\PuCalculator\Enums\PuSettlementSource;
use App\Domain\PuCalculator\Enums\PuSettlementStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Lançamento do livro de liquidações do PU (Fase 5): o que de fato foi
 * liquidado, de onde veio a informação e quem a registrou.
 *
 * Evidência financeira. Os fatos (obrigação, data, valor, moeda, componentes,
 * origem, referência, quem registrou) nunca mudam e o lançamento nunca é
 * apagado. A única mudança permitida é o fim da vigência: `active` → `corrected`
 * ou `reversed`, apontando o lançamento que o substituiu, uma vez só -- e só o
 * serviço de liquidação faz isso, dentro da correção ou do estorno.
 *
 * Liquidação é fechada: não há valor parcial nem percentual liquidado. A
 * diferença para o esperado é divergência da conciliação.
 */
class EmissionPuSettlement extends Model
{
    /** @var list<string> */
    private const LIFECYCLE_FIELDS = ['status', 'superseded_by_settlement_id', 'superseded_at', 'updated_at'];

    protected $fillable = [
        'emission_id',
        'obligation_id',
        'entry_type',
        'status',
        'predecessor_id',
        'superseded_by_settlement_id',
        'superseded_at',
        'settlement_date',
        'amount',
        'currency',
        'components',
        'source',
        'external_reference',
        'ingestion_key',
        'payload_fingerprint',
        'reason',
        'expected_calculation_id',
        'recorded_by',
        'recorded_via',
        'recorded_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $settlement): void {
            foreach (array_keys($settlement->getDirty()) as $field) {
                if (! in_array($field, self::LIFECYCLE_FIELDS, true)) {
                    throw new LogicException('Settlement facts are immutable: register a correction or a reversal.');
                }
            }

            if ($settlement->isDirty('status')) {
                $from = PuSettlementStatus::tryFrom((string) $settlement->getRawOriginal('status'));
                $to = $settlement->status;

                if ($from !== PuSettlementStatus::Active || ! in_array($to, [PuSettlementStatus::Corrected, PuSettlementStatus::Reversed], true)) {
                    throw new LogicException('Only an active settlement can end, and only by correction or reversal.');
                }
            }

            foreach (['superseded_by_settlement_id', 'superseded_at'] as $field) {
                if ($settlement->isDirty($field) && $settlement->getRawOriginal($field) !== null) {
                    throw new LogicException('A settlement is superseded only once.');
                }
            }
        });

        static::deleting(function (): void {
            throw new LogicException('Settlement entries are financial evidence and are never deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'entry_type' => PuSettlementEntryType::class,
            'status' => PuSettlementStatus::class,
            'superseded_at' => 'datetime',
            'settlement_date' => 'date',
            'amount' => 'decimal:2',
            'components' => 'array',
            'source' => PuSettlementSource::class,
            'recorded_at' => 'datetime',
        ];
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(EmissionPuObligation::class, 'obligation_id');
    }

    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'predecessor_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_settlement_id');
    }

    public function expectedCalculation(): BelongsTo
    {
        return $this->belongsTo(EmissionPuObligationCalculation::class, 'expected_calculation_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function isActive(): bool
    {
        return $this->status === PuSettlementStatus::Active;
    }

    /**
     * Componentes informados pela origem, ou nulo quando ela só trouxe o total.
     *
     * @return array<string, string>|null
     */
    public function providedComponents(): ?array
    {
        return is_array($this->components) && $this->components !== [] ? $this->components : null;
    }
}
