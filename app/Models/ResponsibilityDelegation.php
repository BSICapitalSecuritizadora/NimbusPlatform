<?php

namespace App\Models;

use App\Enums\MeasurementResponsibility;
use Database\Factories\ResponsibilityDelegationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ResponsibilityDelegation extends Model
{
    /** @use HasFactory<ResponsibilityDelegationFactory> */
    use HasFactory, LogsActivity;

    public const SCOPE_GLOBAL = 'global';

    public const SCOPE_OPERATION = 'operation';

    public const SCOPE_STAGE = 'stage';

    public const SCOPE_OPTIONS = [
        self::SCOPE_GLOBAL => 'Todas as responsabilidades',
        self::SCOPE_OPERATION => 'Operação específica',
        self::SCOPE_STAGE => 'Etapa específica',
    ];

    public const STAGE_OPTIONS = [
        1 => 'Engenharia',
        2 => 'Gestão',
        3 => 'Compliance',
        4 => 'Pagamento',
        5 => 'Finalização',
    ];

    protected $fillable = [
        'delegator_user_id',
        'delegate_user_id',
        'scope_type',
        'scope_operation_id',
        'scope_stage',
        'scope_responsibility',
        'starts_at',
        'ends_at',
        'reason',
        'revoked_at',
        'revoked_by',
        'revocation_reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scope_stage' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function delegator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegator_user_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_user_id');
    }

    public function scopeOperation(): BelongsTo
    {
        return $this->belongsTo(Operation::class, 'scope_operation_id');
    }

    public function revokedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->ends_at->isPast();
    }

    public function isActive(): bool
    {
        $now = now();

        return $this->revoked_at === null
            && $this->starts_at <= $now
            && $this->ends_at >= $now;
    }

    public function isScheduled(): bool
    {
        return $this->revoked_at === null && $this->starts_at->isFuture();
    }

    public function scopeActive(Builder $query): Builder
    {
        $now = now();

        return $query->whereNull('revoked_at')
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now);
    }

    public function getStatusAttribute(): string
    {
        if ($this->isRevoked()) {
            return 'revoked';
        }

        if ($this->isExpired()) {
            return 'expired';
        }

        if ($this->isScheduled()) {
            return 'scheduled';
        }

        if ($this->isActive()) {
            return 'active';
        }

        return 'unknown';
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'active' => 'Ativa',
            'scheduled' => 'Agendada',
            'expired' => 'Expirada',
            'revoked' => 'Revogada',
            default => ucfirst($this->status),
        };
    }

    public function getScopeResponsibilityLabelAttribute(): ?string
    {
        return MeasurementResponsibility::tryFrom((string) $this->scope_responsibility)?->label();
    }

    public function covers(Operation $operation, MeasurementResponsibility|int $responsibility): bool
    {
        $resolvedResponsibility = is_int($responsibility)
            ? MeasurementResponsibility::primaryForStage($responsibility)
            : $responsibility;

        if (! $resolvedResponsibility instanceof MeasurementResponsibility) {
            return false;
        }

        return match ($this->scope_type) {
            self::SCOPE_GLOBAL => true,
            self::SCOPE_OPERATION => (int) $this->scope_operation_id === (int) $operation->getKey(),
            self::SCOPE_STAGE => $this->matchesResponsibility($resolvedResponsibility, $operation),
            default => false,
        };
    }

    private function matchesResponsibility(MeasurementResponsibility $responsibility, Operation $operation): bool
    {
        if ((int) $this->scope_stage !== $responsibility->stage()) {
            return false;
        }

        if ($this->scope_operation_id !== null) {
            if ((int) $this->scope_operation_id !== (int) $operation->getKey()) {
                return false;
            }
        }

        // `null` significa "não especificada" e recai na responsabilidade primária da
        // etapa. Qualquer outro valor é comparado literalmente — inclusive string vazia,
        // que não é estado canônico e não cobre nada. Tratá-la como não especificada
        // autorizaria em PHP o que o SQL de visibilidade esconde.
        if ($this->scope_responsibility !== null) {
            return $this->scope_responsibility === $responsibility->value;
        }

        return MeasurementResponsibility::primaryForStage((int) $this->scope_stage) === $responsibility;
    }
}
