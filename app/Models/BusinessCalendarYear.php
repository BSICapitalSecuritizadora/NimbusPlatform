<?php

namespace App\Models;

use Database\Factories\BusinessCalendarYearFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessCalendarYear extends Model
{
    /** @use HasFactory<BusinessCalendarYearFactory> */
    use HasFactory;

    public const STATUS_PROVISIONAL = 'provisional';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_STALE = 'stale';

    protected $fillable = [
        'calendar_code',
        'year',
        'status',
        'source',
        'source_is_official',
        'source_document',
        'source_revision',
        'revision',
        'checksum',
        'confirmed_at',
        'confirmed_by',
    ];

    protected $attributes = [
        'status' => self::STATUS_PROVISIONAL,
        'source_is_official' => false,
        'revision' => 1,
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'source_is_official' => 'boolean',
            'revision' => 'integer',
            'confirmed_at' => 'datetime',
        ];
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function importRuns(): HasMany
    {
        return $this->hasMany(BusinessCalendarImportRun::class);
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(BusinessCalendarOverride::class);
    }
}
