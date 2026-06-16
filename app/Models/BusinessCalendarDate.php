<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BusinessCalendarDate extends Model
{
    /** @use HasFactory<\Database\Factories\BusinessCalendarDateFactory> */
    use HasFactory;

    protected $fillable = [
        'calendar_code',
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
}
