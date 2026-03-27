<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProposalStatusHistory extends Model
{
    /** @use HasFactory<\Database\Factories\ProposalStatusHistoryFactory> */
    use HasFactory;

    protected $fillable = [
        'proposal_id',
        'previous_status',
        'new_status',
        'changed_by_user_id',
        'note',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
        ];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
