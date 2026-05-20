<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class FundBalanceHistory extends Model
{
    /** @use HasFactory<\Database\Factories\FundBalanceHistoryFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'fund_id',
        'date',
        'balance',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'balance' => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }
}
