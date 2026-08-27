<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SlaConfiguration extends Model
{
    use HasFactory;

    protected $fillable = [
        'stage',
        'duration_value',
        'duration_unit',
        'warning_threshold_percent',
        'escalation_threshold_percent',
        'exclude_weekends',
        'exclude_holidays',
        'exclude_paused_time',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'stage' => 'integer',
            'duration_value' => 'integer',
            'warning_threshold_percent' => 'integer',
            'escalation_threshold_percent' => 'integer',
            'exclude_weekends' => 'boolean',
            'exclude_holidays' => 'boolean',
            'exclude_paused_time' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function durationInSeconds(): int
    {
        $hours = $this->duration_unit === 'days' ? $this->duration_value * 24 : $this->duration_value;

        return $hours * 3600;
    }

    public static function activeForStage(int $stage): ?self
    {
        return static::query()->where('stage', $stage)->where('is_active', true)->first();
    }
}
