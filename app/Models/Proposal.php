<?php

namespace App\Models;

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

    public const STATUS_APPROVED = 'aprovado';

    public const STATUS_REJECTED = 'rejeitado';

    protected $fillable = [
        'company_id',
        'contact_id',
        'assigned_representative_id',
        'observations',
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

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_AWAITING_COMPLETION => 'Aguardando complementação',
            self::STATUS_IN_REVIEW => 'Em análise',
            self::STATUS_APPROVED => 'Aprovado',
            self::STATUS_REJECTED => 'Rejeitado',
            'pendente' => 'Pendente',
            default => ucfirst((string) $this->status),
        };
    }
}
