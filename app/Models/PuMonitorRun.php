<?php

namespace App\Models;

use App\Domain\PuCalculator\Enums\PuMonitorRunStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Uma execução do monitor operacional do PU e o que ela conseguiu verificar
 * (Fase 6). É o que separa "tudo certo" de "o monitor não rodou".
 */
class PuMonitorRun extends Model
{
    protected $fillable = [
        'status',
        'trigger',
        'started_at',
        'finished_at',
        'checks',
        'conditions_count',
        'incidents_opened',
        'incidents_updated',
        'incidents_resolved',
        'notifications_sent',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'status' => PuMonitorRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'checks' => 'array',
            'conditions_count' => 'integer',
            'incidents_opened' => 'integer',
            'incidents_updated' => 'integer',
            'incidents_resolved' => 'integer',
            'notifications_sent' => 'integer',
        ];
    }
}
