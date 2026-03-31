<?php

namespace App\Models\Nimbus;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessToken extends Model
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected $table = 'nimbus_access_tokens';

    protected $guarded = ['id'];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(PortalUser::class, 'nimbus_portal_user_id');
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null || strtoupper((string) $this->status) === 'USED';
    }

    public function isRevoked(): bool
    {
        return strtoupper((string) $this->status) === 'REVOKED';
    }

    public function isExpired(): bool
    {
        return strtoupper((string) $this->status) === 'EXPIRED'
            || ($this->expires_at !== null && $this->expires_at->isPast() && ! $this->isUsed() && ! $this->isRevoked());
    }

    public function isValid(): bool
    {
        return ! $this->isUsed() && ! $this->isRevoked() && ! $this->isExpired();
    }

    public function getStatusLabelAttribute(): string
    {
        return match (true) {
            $this->isUsed() => 'Utilizada',
            $this->isRevoked() => 'Revogada',
            $this->isExpired() => 'Expirada',
            default => 'Válida',
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match (true) {
            $this->isUsed() => 'success',
            $this->isRevoked() => 'gray',
            $this->isExpired() => 'danger',
            default => 'warning',
        };
    }
}
