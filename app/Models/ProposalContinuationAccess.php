<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;

class ProposalContinuationAccess extends Model
{
    use HasFactory;

    protected $fillable = [
        'proposal_id',
        'token',
        'sent_to_email',
        'code_hash',
        'code_encrypted',
        'sent_at',
        'first_accessed_at',
        'last_accessed_at',
        'expires_at',
        'verified_at',
        'last_used_at',
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

    public function getDisplayCodeAttribute(): string
    {
        if ($this->decrypted_code) {
            return $this->decrypted_code;
        }

        if ($this->revoked_at) {
            return 'Codigo revogado';
        }

        return 'Codigo legado - reenviar acesso';
    }

    public function getGeneratedUrlAttribute(): string
    {
        return URL::temporarySignedRoute(
            'site.proposal.continuation.access',
            $this->expires_at,
            ['access' => $this],
        );
    }

    public function getStatusLabelAttribute(): string
    {
        if ($this->revoked_at) {
            return 'Revogado';
        }

        if ($this->expires_at->isPast()) {
            return 'Expirado';
        }

        if ($this->verified_at) {
            return 'Validado';
        }

        if ($this->first_accessed_at) {
            return 'Acessado';
        }

        return 'Enviado';
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status_label) {
            'Validado' => 'success',
            'Acessado' => 'info',
            'Expirado' => 'warning',
            'Revogado' => 'gray',
            default => 'primary',
        };
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
