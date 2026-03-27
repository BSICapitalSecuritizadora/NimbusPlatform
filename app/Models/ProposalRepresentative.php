<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProposalRepresentative extends Model
{
    /** @use HasFactory<\Database\Factories\ProposalRepresentativeFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'user_id',
        'queue_position',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class, 'assigned_representative_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ProposalAssignment::class, 'representative_id');
    }
}
