<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class MeasurementPlanSet extends Model
{
    /** @use HasFactory<\Database\Factories\MeasurementPlanSetFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'operation_id',
        'construction_id',
        'name',
        'is_default',
        'construction_fund_amount',
        'initial_incurred_amount',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'construction_fund_amount' => 'decimal:2',
            'initial_incurred_amount' => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function construction(): BelongsTo
    {
        return $this->belongsTo(Construction::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MeasurementPlanLine::class, 'plan_set_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(MeasurementAsset::class, 'plan_set_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(MeasurementPayment::class, 'plan_set_id');
    }

    public function getIncurredAmountAttribute(): float
    {
        $paymentsSum = (float) $this->payments()->sum('amount');

        return (float) $this->initial_incurred_amount + $paymentsSum;
    }

    public function getAvailableBalanceAttribute(): float
    {
        return (float) $this->construction_fund_amount - $this->incurred_amount;
    }

    public function getUsedPercentageAttribute(): float
    {
        if ((float) $this->construction_fund_amount <= 0) {
            return 0;
        }

        return ($this->incurred_amount / (float) $this->construction_fund_amount) * 100;
    }
}
