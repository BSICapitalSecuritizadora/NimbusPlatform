<?php

namespace App\Models;

use Database\Factories\BusinessCalendarImportRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessCalendarImportRun extends Model
{
    /** @use HasFactory<BusinessCalendarImportRunFactory> */
    use HasFactory;

    public const RESULT_SUCCEEDED = 'succeeded';

    public const RESULT_CONFLICTS = 'conflicts';

    public const RESULT_COMPLETED_WITH_ERRORS = 'completed_with_errors';

    public const RESULT_FAILED = 'failed';

    protected $fillable = [
        'batch_uuid',
        'business_calendar_year_id',
        'calendar_code',
        'year',
        'source',
        'source_is_official',
        'source_url',
        'source_file',
        'source_document',
        'source_revision',
        'checksum',
        'started_at',
        'finished_at',
        'triggered_by',
        'triggered_by_process',
        'records_found',
        'records_inserted',
        'records_changed',
        'removals_detected',
        'conflicts_detected',
        'errors',
        'result',
        'dry_run',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'source_is_official' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'records_found' => 'integer',
            'records_inserted' => 'integer',
            'records_changed' => 'integer',
            'removals_detected' => 'integer',
            'conflicts_detected' => 'integer',
            'errors' => 'array',
            'dry_run' => 'boolean',
        ];
    }

    public function calendarYear(): BelongsTo
    {
        return $this->belongsTo(BusinessCalendarYear::class, 'business_calendar_year_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
