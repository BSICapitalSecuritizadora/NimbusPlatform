<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Proposal extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id', 'contact_id', 'observations', 'status',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(ProposalCompany::class, 'company_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(ProposalContact::class, 'contact_id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(ProposalProject::class);
    }
}
