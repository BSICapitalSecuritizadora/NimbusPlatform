<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AccessPermission;
use App\Exceptions\DelegationHistoryException;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasAvatar
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsActivity, Notifiable, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'cargo',
        'departamento',
        'avatar_path',
        'phone',
        'bio',
        'approved_at',
        'is_active',
        'last_login_at',
        'invited_by',
        'azure_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'approved_at' => 'datetime',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (self $user): void {
            if ($user->hasResponsibilityDelegationHistory()) {
                throw DelegationHistoryException::forUser();
            }
        });
    }

    /**
     * Aparece em alguma delegação de responsabilidade, como delegante ou como
     * delegado?
     *
     * Vale para qualquer estado da delegação -- vigente, futura, expirada ou
     * revogada. Revogar encerra a autoridade; não apaga o registro de que ela
     * existiu, e é justamente o registro que impede o hard delete.
     */
    public function hasResponsibilityDelegationHistory(): bool
    {
        return ResponsibilityDelegation::query()
            ->where('delegator_user_id', $this->getKey())
            ->orWhere('delegate_user_id', $this->getKey())
            ->exists();
    }

    public function isActive(): bool
    {
        return $this->is_active ?? true;
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    /**
     * Quem pode receber autoridade nova: ativo na plataforma e já provisionado.
     *
     * As duas condições são independentes e significam coisas diferentes.
     * `is_active` é a chave operacional -- reversível, administrativa, e o que
     * bloqueia o login em {@see self::canAccessPanel()} e no middleware.
     * `approved_at` é provisionamento: quem entrou por SSO e ainda não foi
     * liberado não é "desligado", é "ainda não começou". Nenhum dos dois estados
     * recebe responsabilidade nova, por motivos opostos.
     *
     * Este é o predicado único de elegibilidade operacional. Não existe global
     * scope: usuário inativo continua aparecendo em auditoria, em delegação
     * histórica e em toda relação já gravada. O filtro vale só para *escolha
     * nova* -- select de responsável, select de delegação, destinatário de
     * notificação operacional.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOperational(Builder $query): Builder
    {
        return $this->scopeApproved($this->scopeActive($query));
    }

    /**
     * Tolerante a `NULL` de propósito: a coluna é NOT NULL DEFAULT 1 no MySQL,
     * mas {@see self::isActive()} lê `?? true`, e o SQL precisa concordar com o
     * PHP -- foi assim que a efetividade de delegação sempre decidiu.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $active): void {
            $active->where('is_active', true)->orWhereNull('is_active');
        });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->whereNotNull('approved_at');
    }

    public function isOperational(): bool
    {
        return $this->isActive() && $this->isApproved();
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatarUrl();
    }

    public function avatarUrl(): ?string
    {
        if (blank($this->avatar_path)) {
            return null;
        }

        return Storage::disk('public')->url($this->avatar_path);
    }

    public function hasAvatar(): bool
    {
        return filled($this->avatar_path) && Storage::disk('public')->exists($this->avatar_path);
    }

    public function proposalRepresentative(): HasOne
    {
        return $this->hasOne(ProposalRepresentative::class);
    }

    public function invitedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->isActive() || ! $this->isApproved()) {
            return false;
        }

        return $this->hasAnyRole([
            'super-admin',
            'admin',
            'editor',
            'commercial-representative',
        ]) || $this->canAny(AccessPermission::panelEntryValues());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'cargo', 'departamento', 'avatar_path', 'phone', 'bio', 'is_active', 'approved_at', 'azure_id', 'invited_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
