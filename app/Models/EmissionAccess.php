<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

class EmissionAccess extends Model
{
    /** @use HasFactory<\Database\Factories\EmissionAccessFactory> */
    use HasFactory;

    protected $fillable = [
        'emission_id',
        'token',
        'requester_name',
        'requester_email',
        'requester_phone',
        'code_hash',
        'code_encrypted',
        'sent_at',
        'first_accessed_at',
        'last_accessed_at',
        'verified_at',
        'last_used_at',
        'expires_at',
        'revoked_at',
    ];

    protected $hidden = [
        'code_hash',
        'code_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'first_accessed_at' => 'datetime',
            'last_accessed_at' => 'datetime',
            'verified_at' => 'datetime',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    public static function authorizationSessionKeyForEmission(int $emissionId): string
    {
        return "emission_access_authorized.{$emissionId}";
    }

    public function authorizationSessionKey(): string
    {
        return self::authorizationSessionKeyForEmission($this->emission_id);
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function isActive(): bool
    {
        return is_null($this->revoked_at) && $this->expires_at?->isFuture();
    }

    public function isVerified(): bool
    {
        return ! is_null($this->verified_at);
    }

    public function getStatusLabelAttribute(): string
    {
        return match (true) {
            ! is_null($this->revoked_at) => 'Revogado',
            $this->expires_at?->isPast() ?? false => 'Expirado',
            ! is_null($this->verified_at) => 'Validado',
            ! is_null($this->sent_at) => 'Enviado',
            default => 'Pendente',
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status_label) {
            'Validado' => 'success',
            'Enviado' => 'warning',
            'Revogado' => 'danger',
            'Expirado' => 'gray',
            default => 'gray',
        };
    }

    public function matchesCode(string $code): bool
    {
        return Hash::check($code, $this->code_hash);
    }

    public function getDecryptedCodeAttribute(): ?string
    {
        if (! $this->code_encrypted) {
            return null;
        }

        try {
            return Crypt::decryptString($this->code_encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    public function markLinkOpened(): void
    {
        $now = now();

        $this->forceFill([
            'first_accessed_at' => $this->first_accessed_at ?? $now,
            'last_accessed_at' => $now,
        ])->save();
    }

    public function markVerified(): void
    {
        $now = now();

        $this->forceFill([
            'verified_at' => $this->verified_at ?? $now,
            'last_accessed_at' => $now,
            'last_used_at' => $now,
        ])->save();
    }

    public function markAuthorizedUsage(): void
    {
        $now = now();

        $this->forceFill([
            'last_accessed_at' => $now,
            'last_used_at' => $now,
        ])->save();
    }
}
