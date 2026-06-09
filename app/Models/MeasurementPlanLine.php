<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class MeasurementPlanLine extends Model
{
    /** @use HasFactory<\Database\Factories\MeasurementPlanLineFactory> */
    use HasFactory, LogsActivity;

    public const TREND_AHEAD = 'Acima';

    public const TREND_ON_TRACK = 'Na média';

    public const TREND_BEHIND = 'Abaixo';

    protected $fillable = [
        'plan_set_id',
        'operation_id',
        'sequence_number',
        'planned_monthly_percent',
        'planned_cumulative_percent',
        'initial_realized_cumulative_percent',
        'realized_monthly_percent',
        'realized_cumulative_percent',
        'evolution_diff_percent',
        'evolution_trend',
        'measurement_date',
        'measurement_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $line): void {
            if (blank($line->operation_id) && filled($line->plan_set_id)) {
                $line->operation_id = MeasurementPlanSet::whereKey($line->plan_set_id)->value('operation_id');
            }

            $diff = round((float) $line->realized_cumulative_percent - (float) $line->planned_cumulative_percent, 2);
            $line->evolution_diff_percent = $diff;
            $line->evolution_trend = self::resolveTrend($diff);
        });
    }

    protected function casts(): array
    {
        return [
            'sequence_number' => 'integer',
            'planned_monthly_percent' => 'decimal:2',
            'planned_cumulative_percent' => 'decimal:2',
            'initial_realized_cumulative_percent' => 'decimal:2',
            'realized_monthly_percent' => 'decimal:2',
            'realized_cumulative_percent' => 'decimal:2',
            'evolution_diff_percent' => 'decimal:2',
            'measurement_date' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function planSet(): BelongsTo
    {
        return $this->belongsTo(MeasurementPlanSet::class, 'plan_set_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function measurement(): BelongsTo
    {
        return $this->belongsTo(Measurement::class);
    }

    public static function resolveTrend(float $diff): string
    {
        return match (true) {
            $diff > 0.0 => self::TREND_AHEAD,
            $diff < 0.0 => self::TREND_BEHIND,
            default => self::TREND_ON_TRACK,
        };
    }
}
