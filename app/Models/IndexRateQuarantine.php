<?php

namespace App\Models;

use App\Domain\PuCalculator\Enums\PuIndexAnomalyType;
use App\Domain\PuCalculator\Enums\PuIndexQuarantineStatus;
use Database\Factories\IndexRateQuarantineFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Observação de índice recebida da fonte e retida para revisão (Fase 8.1).
 *
 * É evidência, não índice: nenhum leitor de índice realizado consulta esta
 * tabela, e aprovar não muda esta linha para "aceita" -- grava a observação pelo
 * caminho governado e guarda aqui o resultado. A linha nunca é apagada; a
 * decisão e o encerramento por outra observação ficam registrados nela.
 *
 * @property PuIndexAnomalyType $anomaly_type
 * @property PuIndexQuarantineStatus $status
 */
class IndexRateQuarantine extends Model
{
    /** @use HasFactory<IndexRateQuarantineFactory> */
    use HasFactory;

    protected $fillable = [
        'indexer',
        'rate_date',
        'source',
        'external_series_code',
        'anomaly_type',
        'received_value',
        'raw_value',
        'reason',
        'reference_value',
        'reference_date',
        'fingerprint',
        'open_fingerprint',
        'status',
        'occurrences',
        'first_detected_at',
        'last_detected_at',
        'first_sync_attempt_id',
        'last_sync_attempt_id',
        'decided_by',
        'decided_at',
        'decision_reason',
        'resulting_index_rate_id',
        'resulting_correction_id',
        'superseded_by_id',
    ];

    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new LogicException('Index rate quarantine evidence is never deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'rate_date' => 'date',
            'reference_date' => 'date',
            'anomaly_type' => PuIndexAnomalyType::class,
            'status' => PuIndexQuarantineStatus::class,
            'received_value' => 'decimal:8',
            'reference_value' => 'decimal:8',
            'occurrences' => 'integer',
            'first_detected_at' => 'datetime',
            'last_detected_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === PuIndexQuarantineStatus::PendingReview;
    }

    /**
     * @param  Builder<IndexRateQuarantine>  $query
     * @return Builder<IndexRateQuarantine>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', PuIndexQuarantineStatus::PendingReview->value);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return BelongsTo<PuIndexSyncAttempt, $this>
     */
    public function lastSyncAttempt(): BelongsTo
    {
        return $this->belongsTo(PuIndexSyncAttempt::class, 'last_sync_attempt_id');
    }

    /**
     * @return BelongsTo<IndexRate, $this>
     */
    public function resultingIndexRate(): BelongsTo
    {
        return $this->belongsTo(IndexRate::class, 'resulting_index_rate_id');
    }

    /**
     * @return BelongsTo<IndexRateCorrection, $this>
     */
    public function resultingCorrection(): BelongsTo
    {
        return $this->belongsTo(IndexRateCorrection::class, 'resulting_correction_id');
    }
}
