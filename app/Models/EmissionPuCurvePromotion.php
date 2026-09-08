<?php

namespace App\Models;

use App\Domain\PuCalculator\Enums\PuCurvePromotionStatus;
use Database\Factories\EmissionPuCurvePromotionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Dossiê de promoção operacional: registra o pedido, a decisão independente e a
 * execução da troca da curva vigente.
 *
 * O artefato é append-only. A identidade capturada no pedido (candidate,
 * checksums, validação externa e a versão operacional vigente no momento) é
 * imutável: ela é justamente o baseline contra o qual a execução revalida tudo,
 * então permitir edição destruiria a detecção de TOCTOU.
 */
class EmissionPuCurvePromotion extends Model
{
    /** @use HasFactory<EmissionPuCurvePromotionFactory> */
    use HasFactory;

    /**
     * Únicos campos graváveis pela decisão de review. `updated_at` entra porque o
     * Eloquent toca o timestamp em qualquer save.
     *
     * @var list<string>
     */
    public const REVIEW_MUTABLE_FIELDS = [
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_reason',
        'updated_at',
    ];

    /**
     * Únicos campos graváveis pela execução da troca operacional.
     *
     * @var list<string>
     */
    public const EXECUTION_MUTABLE_FIELDS = [
        'status',
        'executed_by',
        'promoted_at',
        'updated_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $promotion): void {
            $original = PuCurvePromotionStatus::tryFrom((string) $promotion->getRawOriginal('status'));

            if ($original?->isFinalDecision() ?? true) {
                throw new LogicException('A final PU curve promotion decision is immutable.');
            }

            $dirty = array_keys($promotion->getDirty());

            if ($original === PuCurvePromotionStatus::PendingReview) {
                if (array_diff($dirty, self::REVIEW_MUTABLE_FIELDS) !== []
                    || ! in_array($promotion->status, [
                        PuCurvePromotionStatus::Approved,
                        PuCurvePromotionStatus::Rejected,
                    ], true)) {
                    throw new LogicException('A pending PU curve promotion only accepts a final review decision.');
                }

                return;
            }

            if (array_diff($dirty, self::EXECUTION_MUTABLE_FIELDS) !== []
                || $promotion->status !== PuCurvePromotionStatus::Executed) {
                throw new LogicException('An approved PU curve promotion only accepts execution.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('PU curve promotion dossiers cannot be deleted.');
        });
    }

    protected $fillable = [
        'emission_id',
        'candidate_curve_version_id',
        'previous_operational_curve_version_id',
        'external_validation_id',
        'calculation_version',
        'candidate_checksum',
        'input_fingerprint',
        'benchmark_dataset_sha256',
        'comparison_sha256',
        'rows_count',
        'status',
        'requested_by',
        'requested_at',
        'reviewed_by',
        'reviewed_at',
        'review_reason',
        'executed_by',
        'promoted_at',
    ];

    protected $attributes = [
        'status' => PuCurvePromotionStatus::PendingReview->value,
    ];

    protected function casts(): array
    {
        return [
            'status' => PuCurvePromotionStatus::class,
            'rows_count' => 'integer',
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'promoted_at' => 'datetime',
        ];
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(EmissionPuCurveVersion::class, 'candidate_curve_version_id');
    }

    public function previousOperationalVersion(): BelongsTo
    {
        return $this->belongsTo(EmissionPuCurveVersion::class, 'previous_operational_curve_version_id');
    }

    public function externalValidation(): BelongsTo
    {
        return $this->belongsTo(EmissionPuExternalValidation::class, 'external_validation_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    /**
     * @param  Builder<EmissionPuCurvePromotion>  $query
     * @return Builder<EmissionPuCurvePromotion>
     */
    public function scopeExecuted(Builder $query): Builder
    {
        return $query->where('status', PuCurvePromotionStatus::Executed->value);
    }
}
