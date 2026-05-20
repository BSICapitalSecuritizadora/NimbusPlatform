<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Payment extends Model
{
    use LogsActivity;

    protected $fillable = [
        'emission_id',
        'payment_date',
        'premium_value',
        'interest_value',
        'amortization_value',
        'extra_amortization_value',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'premium_value' => 'decimal:2',
            'interest_value' => 'decimal:2',
            'amortization_value' => 'decimal:2',
            'extra_amortization_value' => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }
}
