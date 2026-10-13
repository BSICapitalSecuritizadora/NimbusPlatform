<?php

namespace App\Models;

use App\Domain\PuCalculator\Enums\PuOperationalActivationStatus;
use App\Domain\PuCalculator\Services\PuOperationalActivationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Decisão do livro de ativação operacional do PU (Fase 7): quem ativou ou
 * suspendeu a operação do PU de uma emissão, quando, de que situação, por quê,
 * com que referência de aprovação e com que avaliação de prontidão na mão.
 *
 * Só de inclusão: a situação vigente é a última decisão da emissão, e uma
 * decisão nunca muda nem se apaga -- suspender é uma decisão nova, reativar
 * também. Só o {@see PuOperationalActivationService} grava, com a emissão travada.
 */
class EmissionPuOperationalStatusChange extends Model
{
    protected $fillable = [
        'emission_id',
        'status',
        'previous_status',
        'reason',
        'approval_reference',
        'readiness_status',
        'readiness_snapshot',
        'decided_by',
        'decided_via',
        'decided_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $change): void {
            if (! in_array($change->status, PuOperationalActivationStatus::recordable(), true)) {
                throw new LogicException('Only activation and suspension decisions are recorded.');
            }
        });

        static::updating(function (): never {
            throw new LogicException('PU operational activation decisions are immutable: record a new decision.');
        });

        static::deleting(function (): never {
            throw new LogicException('PU operational activation decisions are audit evidence and are never deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'status' => PuOperationalActivationStatus::class,
            'previous_status' => PuOperationalActivationStatus::class,
            'readiness_snapshot' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
