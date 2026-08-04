<?php

namespace App\Models;

use App\Enums\MalwareScanStatus;
use App\Observers\ObligationEvidenceObserver;
use Database\Factories\ObligationEvidenceFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Number;

#[ObservedBy(ObligationEvidenceObserver::class)]
class ObligationEvidence extends Model
{
    /** @use HasFactory<ObligationEvidenceFactory> */
    use HasFactory, SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_OPTIONS = [
        self::STATUS_PENDING => 'Pendente de aprovação',
        self::STATUS_APPROVED => 'Aprovada',
        self::STATUS_REJECTED => 'Rejeitada',
    ];

    protected $table = 'obligation_evidences';

    protected $attributes = [
        'scan_status' => MalwareScanStatus::Pending->value,
    ];

    protected $fillable = [
        'obligation_id',
        'emission_id',
        'uploaded_by',
        'original_name',
        'path',
        'disk',
        'mime_type',
        'size',
        'description',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'rejection_reason',
        'uploaded_at',
        'scan_status',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'reviewed_at' => 'datetime',
            'uploaded_at' => 'datetime',
            'scan_status' => MalwareScanStatus::class,
        ];
    }

    public function getHumanSizeAttribute(): string
    {
        return $this->size ? Number::fileSize($this->size) : '—';
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_OPTIONS[$this->status] ?? $this->status;
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(Obligation::class);
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_REJECTED);
    }
}
