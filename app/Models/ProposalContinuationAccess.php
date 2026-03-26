<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

class ProposalContinuationAccess extends Model
{
    use HasFactory;

    protected $fillable = [
        'proposal_id',
        'token',
        'sent_to_email',
        'code_hash',
        'expires_at',
        'verified_at',
        'last_used_at',
        'revoked_at',
    ];

    protected $hidden = [
        'code_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    public function isActive(): bool
    {
        return is_null($this->revoked_at) && $this->expires_at->isFuture();
    }

    public function matchesCode(string $code): bool
    {
        return Hash::check($code, $this->code_hash);
    }
}
