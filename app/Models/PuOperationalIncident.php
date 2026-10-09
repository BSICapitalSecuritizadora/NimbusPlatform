<?php

namespace App\Models;

use App\Domain\PuCalculator\Enums\PuIncidentStatus;
use App\Domain\PuCalculator\Enums\PuOperationalConditionType;
use App\Domain\PuCalculator\Enums\PuOperationalSeverity;
use Database\Factories\PuOperationalIncidentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Condição operacional do PU que pede ação (Fase 6).
 *
 * Uma por identidade enquanto aberta (`open_key` UNIQUE): repetir a verificação
 * atualiza a mesma, nunca abre outra. Só o monitor resolve, e só quando a
 * condição de domínio deixou de existir; reconhecer não corrige nada. Um
 * incidente nunca é apagado pela aplicação: o resolvido é o histórico.
 */
class PuOperationalIncident extends Model
{
    /** @use HasFactory<PuOperationalIncidentFactory> */
    use HasFactory;

    protected $fillable = [
        'incident_key',
        'type',
        'check_name',
        'severity',
        'status',
        'emission_id',
        'curve_version_id',
        'obligation_id',
        'settlement_conflict_id',
        'indexer',
        'business_date',
        'reason',
        'context',
        'first_detected_at',
        'last_detected_at',
        'detection_count',
        'last_detected_run_id',
        'recurrence_of_id',
        'acknowledged_at',
        'acknowledged_by',
        'acknowledgement_note',
        'resolved_at',
        'resolution',
        'notified_at',
        'notified_severity',
        'notification_error',
    ];

    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new \LogicException('Operational incidents are history: they are resolved, never deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'type' => PuOperationalConditionType::class,
            'severity' => PuOperationalSeverity::class,
            'status' => PuIncidentStatus::class,
            'notified_severity' => PuOperationalSeverity::class,
            'business_date' => 'date',
            'context' => 'array',
            'first_detected_at' => 'datetime',
            'last_detected_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'notified_at' => 'datetime',
            'detection_count' => 'integer',
            'last_detected_run_id' => 'integer',
        ];
    }

    /**
     * @param  Builder<PuOperationalIncident>  $query
     * @return Builder<PuOperationalIncident>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(EmissionPuObligation::class, 'obligation_id');
    }

    public function settlementConflict(): BelongsTo
    {
        return $this->belongsTo(EmissionPuSettlementConflict::class, 'settlement_conflict_id');
    }

    public function recurrenceOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'recurrence_of_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
