<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProposalFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'proposal_id',
        'disk',
        'file_path',
        'file_name',
        'original_name',
        'mime_type',
        'file_size',
    ];

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }
}
