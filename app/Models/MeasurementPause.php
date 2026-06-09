<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeasurementPause extends Model
{
    /** @use HasFactory<\Database\Factories\MeasurementPauseFactory> */
    use HasFactory;

    protected $fillable = [
        'measurement_id',
        'stage',
        'paused_by',
        'pause_reason',
        'paused_operation_status',
        'paused_at',
        'resumed_at',
        'resumed_by',
    ];

    protected function casts(): array
    {
        return [
            'stage' => 'integer',
            'paused_at' => 'datetime',
            'resumed_at' => 'datetime',
        ];
    }

    public function measurement(): BelongsTo
    {
        return $this->belongsTo(Measurement::class);
    }

    public function pausedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paused_by');
    }

    public function resumedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resumed_by');
    }
}
