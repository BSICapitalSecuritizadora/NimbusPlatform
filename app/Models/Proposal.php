<?php

namespace App\Models;

use App\Enums\ProposalStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Proposal extends Model
{
    use HasFactory;

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
            'distributed_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
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
        return ProposalStatus::fromValue($this->status)?->canBeCompletedByRequester() ?? false;
    }
}
