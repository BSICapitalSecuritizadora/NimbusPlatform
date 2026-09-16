<?php

namespace App\Models;

use App\Enums\MeasurementReceiptReviewStatus;
use Database\Factories\MeasurementPaymentReceiptEvidenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class MeasurementPaymentReceiptEvidence extends Model
{
    /** @use HasFactory<MeasurementPaymentReceiptEvidenceFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected $table = 'measurement_payment_receipt_evidences';

    protected $attributes = [
        'review_status' => 'pending',
        'is_post_finalization' => false,
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'size' => 'integer',
            'uploaded_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'review_status' => MeasurementReceiptReviewStatus::class,
            'is_post_finalization' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $evidence): void {
            $decisionColumns = ['review_status', 'reviewer_user_id', 'reviewed_at', 'review_notes', 'rejection_reason', 'updated_at'];

            if (array_diff(array_keys($evidence->getDirty()), $decisionColumns) !== []
                || $evidence->getRawOriginal('review_status') !== MeasurementReceiptReviewStatus::Pending->value
                || ! in_array($evidence->review_status, [MeasurementReceiptReviewStatus::Approved, MeasurementReceiptReviewStatus::Rejected], true)) {
                throw new LogicException('A evidência e sua decisão são imutáveis. Envie uma nova versão.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Evidências de pagamento não podem ser excluídas.');
        });
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(MeasurementPayment::class, 'measurement_payment_id');
    }

    public function uploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function supersededBy(): HasOne
    {
        return $this->hasOne(self::class, 'supersedes_id');
    }

    public function getResolvedStorageDiskAttribute(): string
    {
        return $this->storage_disk ?: 'public';
    }
}
