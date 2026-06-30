<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessHoliday extends Model
{
    /** @use HasFactory<\Database\Factories\BusinessHolidayFactory> */
    use HasFactory;

    protected $fillable = [
        'calendar_code',
        'holiday_date',
        'name',
        'source',
        'source_file',
        'imported_at',
        'imported_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'holiday_date' => 'date',
            'imported_at' => 'datetime',
        ];
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
