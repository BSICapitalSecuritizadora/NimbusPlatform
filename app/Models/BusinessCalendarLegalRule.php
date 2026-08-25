<?php

namespace App\Models;

use Database\Factories\BusinessCalendarLegalRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessCalendarLegalRule extends Model
{
    /** @use HasFactory<BusinessCalendarLegalRuleFactory> */
    use HasFactory;

    public const TYPE_FIXED_NATIONAL_HOLIDAY = 'fixed_national_holiday';

    public const TYPE_SCOPE_DEFINITION = 'scope_definition';

    public const TYPE_HISTORICAL_OBSERVANCE = 'historical_observance';

    protected $fillable = [
        'calendar_code',
        'rule_key',
        'rule_type',
        'name',
        'month',
        'day',
        'effective_from',
        'effective_until',
        'norm_identification',
        'article_reference',
        'source_url',
        'source_fingerprint',
        'verified_at',
        'verified_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'integer',
            'day' => 'integer',
            'effective_from' => 'date',
            'effective_until' => 'date',
            'verified_at' => 'datetime',
        ];
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(BusinessHoliday::class, 'business_calendar_legal_rule_id');
    }
}
