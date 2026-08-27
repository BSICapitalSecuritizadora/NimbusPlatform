<?php

namespace App\Models;

use App\Domain\PuCalculator\Enums\PuBaselineEvidenceDocumentType;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceStatus;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceType;
use Database\Factories\EmissionPuBaselineEvidenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class EmissionPuBaselineEvidence extends Model
{
    /** @use HasFactory<EmissionPuBaselineEvidenceFactory> */
    use HasFactory, LogsActivity;

    protected $table = 'emission_pu_baseline_evidence';

    protected $fillable = [
        'emission_id',
        'document_id',
        'evidence_type',
        'document_type',
        'evidenced_value',
        'reference',
        'confidence',
        'status',
        'notes',
        'created_by',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected $attributes = [
        'confidence' => 'high',
        'status' => PuBaselineEvidenceStatus::PendingReview->value,
    ];

    protected function casts(): array
    {
        return [
            'evidence_type' => PuBaselineEvidenceType::class,
            'document_type' => PuBaselineEvidenceDocumentType::class,
            'status' => PuBaselineEvidenceStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
