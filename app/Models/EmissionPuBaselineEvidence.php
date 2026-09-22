<?php

namespace App\Models;

use App\Domain\PuCalculator\Enums\PuBaselineEvidenceDocumentType;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceStatus;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceType;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceValueOrigin;
use App\Domain\PuCalculator\Services\PuBaselineEvidenceExtractionService;
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
        'page',
        'excerpt',
        'confidence',
        'value_origin',
        'status',
        'notes',
        'extraction',
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
            'value_origin' => PuBaselineEvidenceValueOrigin::class,
            'page' => 'integer',
            'extraction' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        // A proveniência integral já vive na própria linha; o evento
        // `created_pending_review` registra origem e id da extração.
        return LogOptions::defaults()
            ->logFillable()
            ->logExcept(['extraction'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Abre o documento na página em que a referência foi localizada, pela
     * mesma rota inline do dossiê jurídico.
     */
    public function getSourceUrlAttribute(): ?string
    {
        return $this->document === null
            ? null
            : PuBaselineEvidenceExtractionService::sourceUrl($this->document, $this->page);
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
