<?php

namespace App\Models;

use Database\Factories\BusinessCalendarStagedDateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessCalendarStagedDate extends Model
{
    /** @use HasFactory<BusinessCalendarStagedDateFactory> */
    use HasFactory;

    protected $fillable = [
        'business_calendar_staging_batch_id',
        'calendar_date',
        'is_business_day',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'calendar_date' => 'date',
            'is_business_day' => 'boolean',
        ];
    }

    public function stagingBatch(): BelongsTo
    {
        return $this->belongsTo(BusinessCalendarStagingBatch::class, 'business_calendar_staging_batch_id');
    }
}
