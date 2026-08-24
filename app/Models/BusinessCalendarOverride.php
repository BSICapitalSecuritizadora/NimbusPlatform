<?php

namespace App\Models;

use Database\Factories\BusinessCalendarOverrideFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessCalendarOverride extends Model
{
    /** @use HasFactory<BusinessCalendarOverrideFactory> */
    use HasFactory;

    protected $fillable = [
        'business_calendar_year_id',
        'calendar_code',
        'calendar_date',
        'reason',
        'previous_is_business_day',
        'new_is_business_day',
        'created_by',
        'applied_at',
        'revision',
    ];

    protected function casts(): array
    {
        return [
            'calendar_date' => 'date',
            'previous_is_business_day' => 'boolean',
            'new_is_business_day' => 'boolean',
            'applied_at' => 'datetime',
            'revision' => 'integer',
        ];
    }

    public function calendarYear(): BelongsTo
    {
        return $this->belongsTo(BusinessCalendarYear::class, 'business_calendar_year_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
