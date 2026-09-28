<?php

namespace App\Models;

use Database\Factories\MeasurementFinancialRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class MeasurementFinancialRule extends Model
{
    /** @use HasFactory<MeasurementFinancialRuleFactory> */
    use HasFactory;

    public const DIRECTIONS = ['under' => 'Para menos', 'over' => 'Para mais', 'both' => 'Para mais ou para menos'];

    protected $guarded = ['id'];

    protected $attributes = ['version' => 1, 'requires_document' => false];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date', 'effective_until' => 'date', 'retired_at' => 'datetime',
            'maximum_difference_amount' => 'decimal:2', 'maximum_difference_percent' => 'decimal:4',
            'requires_document' => 'boolean', 'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $rule): void {
            if (array_diff(array_keys($rule->getDirty()), ['retired_at', 'updated_at']) !== []
                || $rule->getRawOriginal('retired_at') !== null || $rule->retired_at === null) {
                throw new LogicException('Registre uma nova versão para alterar as condições da regra.');
            }
        });
        static::deleting(function (): never {
            throw new LogicException('Regras financeiras não podem ser excluídas. Encerre a regra.');
        });
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function construction(): BelongsTo
    {
        return $this->belongsTo(Construction::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return $this->only(['id', 'emission_id', 'construction_id', 'name', 'description', 'direction',
            'maximum_difference_amount', 'maximum_difference_percent', 'requires_document', 'version']) + [
                'effective_from' => $this->effective_from->toDateString(),
                'effective_until' => $this->effective_until?->toDateString(),
            ];
    }
}
