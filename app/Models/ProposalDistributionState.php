<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProposalDistributionState extends Model
{
    use HasFactory;

    protected $fillable = [
        'last_representative_id',
        'last_sequence',
    ];

    public function representative(): BelongsTo
    {
        return $this->belongsTo(ProposalRepresentative::class, 'last_representative_id');
    }
}
