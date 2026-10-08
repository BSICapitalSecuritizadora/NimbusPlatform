<?php

namespace App\Models;

use App\Domain\PuCalculator\Enums\PuReconciliationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Um resultado da conciliação de uma obrigação, com a proveniência: o cálculo
 * esperado e a liquidação comparados, a diferença e o detalhe da divergência.
 * Só de inclusão -- um resultado novo entra quando o anterior deixa de valer. A
 * conciliação é sempre refeita a partir dos fatos; este histórico explica o que
 * ela disse e quando.
 */
class EmissionPuReconciliation extends Model
{
    protected $fillable = [
        'obligation_id',
        'emission_id',
        'status',
        'reason',
        'calculation_id',
        'settlement_id',
        'expected_total',
        'actual_total',
        'difference',
        'divergence',
        'result_fingerprint',
        'trigger',
        'evaluated_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Reconciliation results are append-only.');
        });

        static::deleting(function (): void {
            throw new LogicException('Reconciliation results are never deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'status' => PuReconciliationStatus::class,
            'expected_total' => 'decimal:2',
            'actual_total' => 'decimal:2',
            'difference' => 'decimal:2',
            'divergence' => 'array',
            'evaluated_at' => 'datetime',
        ];
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(EmissionPuObligation::class, 'obligation_id');
    }

    public function calculation(): BelongsTo
    {
        return $this->belongsTo(EmissionPuObligationCalculation::class, 'calculation_id');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(EmissionPuSettlement::class, 'settlement_id');
    }
}
