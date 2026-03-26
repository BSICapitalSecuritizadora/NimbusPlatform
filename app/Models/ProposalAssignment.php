<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProposalAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'proposal_id',
        'representative_id',
        'sequence',
        'strategy',
        'assigned_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
        ];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    public function representative(): BelongsTo
    {
        return $this->belongsTo(ProposalRepresentative::class, 'representative_id');
    }
}
