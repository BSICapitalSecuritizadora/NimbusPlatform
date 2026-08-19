<?php

namespace App\Models;

use App\Enums\ObligationDueRuleType;
use App\Enums\ObligationFrequency;
use App\Enums\ObligationInvalidDayPolicy;
use Database\Factories\ObligationSeriesRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ObligationSeriesRule extends Model
{
    /** @use HasFactory<ObligationSeriesRuleFactory> */
    use HasFactory;

    protected $fillable = [
        'obligation_series_id',
        'version',
        'effective_from',
        'frequency',
        'due_rule_type',
        'due_day',
        'due_offset_months',
        'due_offset_days',
        'invalid_day_policy',
        'calendar_code',
        'created_by',
        'change_reason',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'effective_from' => 'date',
            'frequency' => ObligationFrequency::class,
            'due_rule_type' => ObligationDueRuleType::class,
            'due_day' => 'integer',
            'due_offset_months' => 'integer',
            'due_offset_days' => 'integer',
            'invalid_day_policy' => ObligationInvalidDayPolicy::class,
        ];
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(ObligationSeries::class, 'obligation_series_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(Obligation::class, 'obligation_series_rule_id');
    }
}
