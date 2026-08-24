<?php

namespace App\Models;

use Database\Factories\BusinessCalendarSelectionEvidenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BusinessCalendarSelectionEvidence extends Model
{
    /** @use HasFactory<BusinessCalendarSelectionEvidenceFactory> */
    use HasFactory;

    protected $table = 'business_calendar_selection_evidence';

    protected $fillable = [
        'subject_type',
        'subject_id',
        'context',
        'calendar_code',
        'source_document',
        'clause_reference',
        'page_reference',
        'excerpt',
        'notes',
        'confirmed_by',
        'confirmed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime'];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(BusinessCalendar::class, 'calendar_code', 'code');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
