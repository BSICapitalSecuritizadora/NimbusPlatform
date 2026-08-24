<?php

namespace App\Models;

use App\Enums\ObligationDueRuleType;
use App\Enums\ObligationFrequency;
use App\Enums\ObligationInitialDateInclusion;
use App\Enums\ObligationInvalidDayPolicy;
use App\Enums\ObligationOffsetDirection;
use Database\Factories\ObligationSeriesRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ObligationSeriesRule extends Model
{
    public const CALENDAR_EVIDENCE_CONTEXT = 'obligation_business_day_calendar';

    /** @use HasFactory<ObligationSeriesRuleFactory> */
    use HasFactory;

    protected $fillable = [
        'obligation_series_id',
        'version',
        'effective_from',
        'frequency',
        'due_rule_type',
        'due_day',
        'due_offset_months',
        'due_offset_days',
        'relative_offset_quantity',
        'relative_offset_unit',
        'relative_offset_direction',
        'anchor_description',
        'initial_date_inclusion',
        'invalid_day_policy',
        'calendar_code',
        'created_by',
        'change_reason',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'effective_from' => 'date',
            'frequency' => ObligationFrequency::class,
            'due_rule_type' => ObligationDueRuleType::class,
            'due_day' => 'integer',
            'due_offset_months' => 'integer',
            'due_offset_days' => 'integer',
            'relative_offset_quantity' => 'integer',
            'relative_offset_direction' => ObligationOffsetDirection::class,
            'initial_date_inclusion' => ObligationInitialDateInclusion::class,
            'invalid_day_policy' => ObligationInvalidDayPolicy::class,
        ];
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(ObligationSeries::class, 'obligation_series_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(Obligation::class, 'obligation_series_rule_id');
    }

    public function anchorEvents(): HasMany
    {
        return $this->hasMany(ObligationAnchorEvent::class, 'obligation_series_rule_id');
    }

    public function calendarSelectionEvidence(): MorphMany
    {
        return $this->morphMany(BusinessCalendarSelectionEvidence::class, 'subject');
    }

    public function hasConfirmedCalendarEvidence(): bool
    {
        if ($this->relationLoaded('calendarSelectionEvidence')) {
            return $this->calendarSelectionEvidence
                ->contains(fn (BusinessCalendarSelectionEvidence $evidence): bool => $evidence->confirmed_at !== null);
        }

        return $this->calendarSelectionEvidence()->whereNotNull('confirmed_at')->exists();
    }
}
