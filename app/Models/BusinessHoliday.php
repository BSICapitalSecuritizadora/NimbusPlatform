<?php

namespace App\Models;

use Database\Factories\BusinessHolidayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessHoliday extends Model
{
    /** @use HasFactory<BusinessHolidayFactory> */
    use HasFactory;

    protected $fillable = [
        'calendar_code',
        'business_calendar_year_id',
        'business_calendar_legal_rule_id',
        'holiday_date',
        'name',
        'source',
        'data_origin',
        'source_is_official',
        'source_file',
        'source_document',
        'source_revision',
        'checksum',
        'import_run_id',
        'last_seen_import_run_id',
        'removed_detected_at',
        'imported_at',
        'imported_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'holiday_date' => 'date',
            'source_is_official' => 'boolean',
            'removed_detected_at' => 'datetime',
            'imported_at' => 'datetime',
        ];
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function calendarYear(): BelongsTo
    {
        return $this->belongsTo(BusinessCalendarYear::class, 'business_calendar_year_id');
    }

    public function legalRule(): BelongsTo
    {
        return $this->belongsTo(BusinessCalendarLegalRule::class, 'business_calendar_legal_rule_id');
    }

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(BusinessCalendarImportRun::class, 'import_run_id');
    }

    public function lastSeenImportRun(): BelongsTo
    {
        return $this->belongsTo(BusinessCalendarImportRun::class, 'last_seen_import_run_id');
    }
}
