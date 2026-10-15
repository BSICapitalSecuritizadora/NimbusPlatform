<?php

namespace App\Models;

use Database\Factories\FundAllocationRevisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One complete allocation distribution for a period.
 *
 * Lifecycle: draft → pending_review → approved. An approved revision may
 * later be superseded by a correction revision, or stay effective
 * indefinitely. Discarded revisions are inert history. Only draft revisions
 * accept line edits, and only through the allocation service, which also
 * pins the exact reference snapshot every monetary total is validated
 * against.
 */
class FundAllocationRevision extends Model
{
    /** @use HasFactory<FundAllocationRevisionFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_DISCARDED = 'discarded';

    /**
     * @var array<string, string>
     */
    public const STATUS_OPTIONS = [
        self::STATUS_DRAFT => 'Rascunho',
        self::STATUS_PENDING_REVIEW => 'Em análise',
        self::STATUS_APPROVED => 'Aprovada',
        self::STATUS_SUPERSEDED => 'Substituída',
        self::STATUS_DISCARDED => 'Descartada',
    ];

    protected $fillable = [
        'fund_allocation_period_id',
        'revision_number',
        'status',
        'bank_application_balance_snapshot_id',
        'reference_amount',
        'total_amount',
        'unallocated_amount',
        'proposed_by_user_id',
        'submitted_by_user_id',
        'submitted_at',
        'approved_by_user_id',
        'approved_at',
        'approval_idempotency_key',
        'supersedes_revision_id',
        'correction_reason',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    protected function casts(): array
    {
        return [
            'reference_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'unallocated_amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(FundAllocationPeriod::class, 'fund_allocation_period_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FundAllocationLine::class);
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(BankApplicationBalanceSnapshot::class, 'bank_application_balance_snapshot_id');
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by_user_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_revision_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPendingReview(): bool
    {
        return $this->status === self::STATUS_PENDING_REVIEW;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isOpen(): bool
    {
        return $this->isDraft() || $this->isPendingReview();
    }

    public function isCorrection(): bool
    {
        return $this->supersedes_revision_id !== null;
    }

    public function statusLabel(): string
    {
        return self::STATUS_OPTIONS[$this->status] ?? (string) $this->status;
    }
}
