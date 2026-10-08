<?php

namespace App\Models;

use App\Domain\PuCalculator\Enums\PuSettlementConflictKind;
use App\Domain\PuCalculator\Enums\PuSettlementConflictStatus;
use App\Domain\PuCalculator\Enums\PuSettlementSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Dado de liquidação que chegou e não pôde ser aplicado sem decisão humana: a
 * liquidação existente não é sobrescrita. Fica aberto até ser aceito (vira
 * correção) ou rejeitado; o que chegou é guardado como veio. Nunca é apagado.
 */
class EmissionPuSettlementConflict extends Model
{
    /** @var list<string> */
    private const RESOLUTION_FIELDS = ['status', 'resolved_at', 'resolved_by', 'resolution_reason', 'resolution_settlement_id', 'updated_at'];

    protected $fillable = [
        'emission_id',
        'obligation_id',
        'existing_settlement_id',
        'kind',
        'status',
        'source',
        'external_reference',
        'incoming_payload',
        'payload_fingerprint',
        'detected_at',
        'detected_by',
        'detected_via',
        'resolved_at',
        'resolved_by',
        'resolution_reason',
        'resolution_settlement_id',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $conflict): void {
            if (PuSettlementConflictStatus::tryFrom((string) $conflict->getRawOriginal('status')) !== PuSettlementConflictStatus::Open) {
                throw new LogicException('A resolved settlement conflict is immutable.');
            }

            foreach (array_keys($conflict->getDirty()) as $field) {
                if (! in_array($field, self::RESOLUTION_FIELDS, true)) {
                    throw new LogicException('Only the resolution of a settlement conflict can be recorded.');
                }
            }
        });

        static::deleting(function (): void {
            throw new LogicException('Settlement conflicts are never deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'kind' => PuSettlementConflictKind::class,
            'status' => PuSettlementConflictStatus::class,
            'source' => PuSettlementSource::class,
            'incoming_payload' => 'array',
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<EmissionPuSettlementConflict>  $query
     * @return Builder<EmissionPuSettlementConflict>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', PuSettlementConflictStatus::Open->value);
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(EmissionPuObligation::class, 'obligation_id');
    }

    public function existingSettlement(): BelongsTo
    {
        return $this->belongsTo(EmissionPuSettlement::class, 'existing_settlement_id');
    }

    public function resolutionSettlement(): BelongsTo
    {
        return $this->belongsTo(EmissionPuSettlement::class, 'resolution_settlement_id');
    }
}
