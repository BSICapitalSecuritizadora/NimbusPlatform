<?php

namespace App\Models;

use Database\Factories\ObligationAnchorEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ObligationAnchorEvent extends Model
{
    /** @use HasFactory<ObligationAnchorEventFactory> */
    use HasFactory;

    protected $fillable = [
        'obligation_series_id',
        'obligation_series_rule_id',
        'event_name',
        'occurred_on',
        'notes',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return ['occurred_on' => 'date'];
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(ObligationSeries::class, 'obligation_series_id');
    }

    public function seriesRule(): BelongsTo
    {
        return $this->belongsTo(ObligationSeriesRule::class, 'obligation_series_rule_id');
    }

    public function recordedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function occurrence(): HasOne
    {
        return $this->hasOne(Obligation::class, 'obligation_anchor_event_id');
    }
}
