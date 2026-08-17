<?php

namespace App\Models;

use App\Enums\GuaranteeCoverageStatus;
use App\Enums\GuaranteeLegalStatus;
use App\Enums\GuaranteeValueSource;
use App\Enums\GuaranteeValueStatus;
use Database\Factories\GuaranteeMonthlyPositionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Posição gravada de uma garantia numa competência (§23 do escopo).
 *
 * O que está aqui é o que o relatório daquele mês mostrou, ponto. Recalcular a
 * partir das fontes operacionais daria outro número assim que a carteira ou a
 * curva de PU fossem corrigidas retroativamente.
 */
class GuaranteeMonthlyPosition extends Model
{
    /** @use HasFactory<GuaranteeMonthlyPositionFactory> */
    use HasFactory;

    protected $fillable = [
        'guarantee_id',
        'emission_id',
        'reference_month',
        'current_value',
        'eligible_value',
        'required_value',
        'eligibility_factor',
        'coverage_ratio',
        'surplus_deficit',
        'value_source',
        'value_status',
        'coverage_status',
        'legal_status',
        'outstanding_balance',
        'metadata',
        'computed_at',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            // Formato explícito: a competência é a chave única junto com a
            // garantia, e gravá-la como datetime faria o `updateOrCreate` não
            // reencontrar a linha e violar o índice.
            'reference_month' => 'date:Y-m-d',
            'value_source' => GuaranteeValueSource::class,
            'value_status' => GuaranteeValueStatus::class,
            'coverage_status' => GuaranteeCoverageStatus::class,
            'legal_status' => GuaranteeLegalStatus::class,
            'current_value' => 'decimal:2',
            'eligible_value' => 'decimal:2',
            'required_value' => 'decimal:2',
            'outstanding_balance' => 'decimal:2',
            'surplus_deficit' => 'decimal:2',
            'eligibility_factor' => 'decimal:6',
            'coverage_ratio' => 'decimal:6',
            'metadata' => 'array',
            'computed_at' => 'datetime',
        ];
    }

    public function guarantee(): BelongsTo
    {
        return $this->belongsTo(Guarantee::class);
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function getFormattedReferenceMonthAttribute(): string
    {
        return GuaranteeSnapshot::formatReferenceMonthForDisplay($this->reference_month);
    }

    public function isPending(): bool
    {
        return $this->value_status === GuaranteeValueStatus::Pending;
    }
}
