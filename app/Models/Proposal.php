<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Proposal extends Model
{
    use HasFactory;

    public const STATUS_AWAITING_COMPLETION = 'aguardando_complementacao';

    public const STATUS_IN_REVIEW = 'em_analise';

    public const STATUS_AWAITING_INFORMATION = 'aguardando_informacoes';

    public const STATUS_APPROVED = 'aprovado';

    public const STATUS_REJECTED = 'rejeitado';

    public const STATUS_COMPLETED = 'concluida';

    protected $fillable = [
        'company_id',
        'contact_id',
        'assigned_representative_id',
        'observations',
        'internal_notes',
        'status',
        'distribution_sequence',
        'distributed_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'distributed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(ProposalCompany::class, 'company_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(ProposalContact::class, 'contact_id');
    }

    public function representative(): BelongsTo
    {
        return $this->belongsTo(ProposalRepresentative::class, 'assigned_representative_id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(ProposalProject::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ProposalAssignment::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(ProposalStatusHistory::class);
    }

    public function latestStatusHistory(): HasOne
    {
        return $this->hasOne(ProposalStatusHistory::class)->latestOfMany('changed_at');
    }

    public function continuationAccesses(): HasMany
    {
        return $this->hasMany(ProposalContinuationAccess::class);
    }

    public function latestContinuationAccess(): HasOne
    {
        return $this->hasOne(ProposalContinuationAccess::class)->latestOfMany();
    }

    public function files(): HasMany
    {
        return $this->hasMany(ProposalFile::class);
    }

    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasAnyRole(['super-admin', 'admin'])) {
            return $query;
        }

        $representativeId = $user->proposalRepresentative?->id;

        if (! $representativeId) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('assigned_representative_id', $representativeId);
    }

    public function isAssignedToUser(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(['super-admin', 'admin'])) {
            return true;
        }

        return $this->assigned_representative_id !== null
            && $this->assigned_representative_id === $user->proposalRepresentative?->id;
    }

    public function canBeCompletedByRequester(): bool
    {
        return in_array($this->status, [
            self::STATUS_AWAITING_COMPLETION,
            self::STATUS_AWAITING_INFORMATION,
        ], true);
    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_AWAITING_COMPLETION => 'Aguardando complementação',
            self::STATUS_IN_REVIEW => 'Em análise',
            self::STATUS_AWAITING_INFORMATION => 'Aguardando informações',
            self::STATUS_APPROVED => 'Aprovada',
            self::STATUS_REJECTED => 'Rejeitada',
            self::STATUS_COMPLETED => 'Concluída',
        ];
    }

    public static function allowedStatusTransitions(?string $currentStatus): array
    {
        return match ($currentStatus) {
            self::STATUS_AWAITING_COMPLETION => [
                self::STATUS_IN_REVIEW,
                self::STATUS_REJECTED,
            ],
            self::STATUS_IN_REVIEW => [
                self::STATUS_AWAITING_INFORMATION,
                self::STATUS_APPROVED,
                self::STATUS_REJECTED,
            ],
            self::STATUS_AWAITING_INFORMATION => [
                self::STATUS_IN_REVIEW,
                self::STATUS_REJECTED,
            ],
            self::STATUS_APPROVED => [
                self::STATUS_IN_REVIEW,
                self::STATUS_COMPLETED,
            ],
            self::STATUS_REJECTED => [
                self::STATUS_IN_REVIEW,
            ],
            default => [],
        };
    }

    public function nextAvailableStatusOptions(): array
    {
        return collect(static::allowedStatusTransitions($this->status))
            ->mapWithKeys(fn (string $status): array => [$status => static::statusLabelFor($status)])
            ->all();
    }

    public static function requiresStatusNote(string $status): bool
    {
        return in_array($status, [
            self::STATUS_AWAITING_INFORMATION,
            self::STATUS_REJECTED,
        ], true);
    }

    public static function statusLabelFor(?string $status): string
    {
        return static::statusOptions()[$status] ?? match ($status) {
            'pending', 'pendente' => 'Pendente',
            null => '—',
            default => ucfirst((string) $status),
        };
    }

    public static function statusColorFor(?string $status): string
    {
        return match ($status) {
            self::STATUS_AWAITING_COMPLETION => 'warning',
            self::STATUS_IN_REVIEW => 'info',
            self::STATUS_AWAITING_INFORMATION => 'warning',
            self::STATUS_APPROVED => 'success',
            self::STATUS_REJECTED => 'danger',
            self::STATUS_COMPLETED => 'gray',
            default => 'gray',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return static::statusLabelFor($this->status);
    }

    public function getStatusColorAttribute(): string
    {
        return static::statusColorFor($this->status);
    }
}
