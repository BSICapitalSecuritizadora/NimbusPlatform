<?php

namespace App\Models;

use Database\Factories\BusinessCalendarDateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessCalendarDate extends Model
{
    /** @use HasFactory<BusinessCalendarDateFactory> */
    use HasFactory;

    protected $fillable = [
        'calendar_code',
        'business_calendar_year_id',
        'calendar_date',
        'is_business_day',
        'description',
        'data_origin',
        'source',
        'source_is_official',
        'source_document',
        'source_revision',
        'revision',
        'import_run_id',
    ];

    protected function casts(): array
    {
        return [
            'calendar_date' => 'date',
            'is_business_day' => 'boolean',
            'source_is_official' => 'boolean',
            'revision' => 'integer',
        ];
    }

    public function calendarYear(): BelongsTo
    {
        return $this->belongsTo(BusinessCalendarYear::class, 'business_calendar_year_id');
    }

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(BusinessCalendarImportRun::class, 'import_run_id');
    }
}
