<?php

namespace App\Models;

use App\Domain\PuCalculator\Enums\PuCalendarHomologationDecision;
use App\Domain\PuCalculator\Enums\PuCalendarHomologationStatus;
use Database\Factories\PuCalendarHomologationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PuCalendarHomologation extends Model
{
    /** @use HasFactory<PuCalendarHomologationFactory> */
    use HasFactory;

    protected $fillable = [
        'emission_id',
        'candidate_calendar_code',
        'purpose',
        'period_start',
        'period_end',
        'status',
        'decision',
        'legacy_parameter_snapshot',
        'candidate_parameter_snapshot',
        'evidence_matrix',
        'calendar_governance_snapshot',
        'external_reference',
        'result_summary',
        'daily_diff',
        'first_divergence',
        'comparison_checksum',
        'conclusion_notes',
        'analyzed_by',
        'executed_by',
        'reviewed_by',
        'executed_at',
        'reviewed_at',
    ];

    protected $attributes = [
        'status' => PuCalendarHomologationStatus::Draft->value,
        'purpose' => 'cdi_accrual_dup_and_lookup_lag',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'status' => PuCalendarHomologationStatus::class,
            'decision' => PuCalendarHomologationDecision::class,
            'legacy_parameter_snapshot' => 'array',
            'candidate_parameter_snapshot' => 'array',
            'evidence_matrix' => 'array',
            'calendar_governance_snapshot' => 'array',
            'external_reference' => 'array',
            'result_summary' => 'array',
            'daily_diff' => 'array',
            'first_divergence' => 'array',
            'executed_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function candidateCalendar(): BelongsTo
    {
        return $this->belongsTo(BusinessCalendar::class, 'candidate_calendar_code', 'code');
    }

    public function selectionEvidence(): MorphMany
    {
        return $this->morphMany(BusinessCalendarSelectionEvidence::class, 'subject');
    }

    public function analyzedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'analyzed_by');
    }

    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
