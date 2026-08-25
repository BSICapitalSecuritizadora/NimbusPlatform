<?php

namespace App\Models;

use App\Exceptions\MeasurementWorkflowException;
use Database\Factories\MeasurementPlanLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class MeasurementPlanLine extends Model
{
    /** @use HasFactory<MeasurementPlanLineFactory> */
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
            if ($line->exists
                && $line->isDirty()
                && $line->assets()->whereHas('measurement.reviews', fn ($reviews) => $reviews
                    ->where('stage', 1)
                    ->where('status', 'approved'))->exists()) {
                throw new MeasurementWorkflowException('A linha de cronograma usada por uma Engenharia aprovada está bloqueada.');
            }

            if (blank($line->operation_id) && filled($line->plan_set_id)) {
                $line->operation_id = MeasurementPlanSet::whereKey($line->plan_set_id)->value('operation_id');
            }

            $diff = round((float) $line->realized_cumulative_percent - (float) $line->planned_cumulative_percent, 2);
            $line->evolution_diff_percent = $diff;
            $line->evolution_trend = self::resolveTrend($diff);
        });

        static::deleting(function (self $line): void {
            if ($line->assets()->whereHas('measurement.reviews', fn ($reviews) => $reviews
                ->where('stage', 1)
                ->where('status', 'approved'))->exists()) {
                throw new MeasurementWorkflowException('A linha de cronograma usada por uma Engenharia aprovada não pode ser removida.');
            }
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

    public function assets(): HasMany
    {
        return $this->hasMany(MeasurementAsset::class, 'plan_line_id');
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
