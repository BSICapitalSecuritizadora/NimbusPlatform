<?php

namespace App\Filament\Pages;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\SidebarBehavior;
use App\Enums\TableDensity;
use App\Enums\UserInterfaceTheme;
use App\Models\User;
use App\Models\UserPreference;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Features;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Self-service account center: profile, personalization and security.
 *
 * Always operates on the authenticated user resolved from the session — no
 * record id is ever accepted from the frontend.
 */
class MyAccount extends Page
{
    use PasswordValidationRules;
    use ProfileValidationRules;
    use WithRateLimiting;

    public ?array $profileData = [];

    public ?array $preferencesData = [];

    public ?array $passwordData = [];

    public bool $twoFactorEnabled = false;

    public bool $settingUpTwoFactor = false;

    #[Locked]
    public string $twoFactorQrCode = '';

    #[Locked]
    public string $twoFactorManualKey = '';

    public string $twoFactorCode = '';

    protected string $view = 'filament.pages.my-account';

    protected static ?string $slug = 'minha-conta';

    protected static ?string $title = 'Minha Conta';

    protected static bool $shouldRegisterNavigation = false;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-my-account-page',
    ];

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    public function getSubheading(): ?string
    {
        return 'Gerencie seu perfil, preferências de interface e segurança da conta.';
    }

    public function mount(): void
    {
        $user = $this->getUser();

        $this->twoFactorEnabled = $user->hasEnabledTwoFactorAuthentication();

        $this->profileForm->fill([
            'avatar' => $user->avatar_path,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone ?? '',
            'cargo' => $user->cargo ?? '',
            'departamento' => $user->departamento ?? '',
            'bio' => $user->bio ?? '',
        ]);

        $preferences = UserPreference::forUser($user);

        $this->preferencesForm->fill([
            'theme' => $preferences->theme->value,
            'sidebar_behavior' => $preferences->sidebar_behavior->value,
            'table_density' => $preferences->table_density->value,
            'per_page' => $preferences->per_page,
            'home_page' => $preferences->home_page,
        ]);

        $this->passwordForm->fill();
    }

    public function profileForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('profileData')
            ->components([
                FileUpload::make('avatar')
                    ->label('Foto de perfil')
                    ->image()
                    ->avatar()
                    ->imageEditor()
                    ->imageCropAspectRatio('1:1')
                    ->disk('public')
                    ->directory(fn (): string => 'avatars/'.$this->getUser()->getKey())
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(2048)
                    ->rule('dimensions:min_width=80,min_height=80,max_width=4000,max_height=4000')
                    ->preventFilePathTampering(true, fn (string $file): bool => $file === $this->getUser()->avatar_path)
                    ->afterStateUpdated(function (FileUpload $component): void {
                        // Single-file state dehydrates to its first entry, so a
                        // fresh upload must displace the current path instead of
                        // sitting behind it — this enforces replace semantics.
                        $rawState = $component->getRawState();

                        if (! is_array($rawState)) {
                            return;
                        }

                        $freshUploads = array_filter(
                            $rawState,
                            fn (mixed $file): bool => $file instanceof TemporaryUploadedFile,
                        );

                        if ($freshUploads === [] || count($freshUploads) === count($rawState)) {
                            return;
                        }

                        $component->rawState(array_values($freshUploads));
                    })
                    ->helperText('JPG, PNG ou WebP. Máximo 2 MB. Mínimo 80×80px, com recorte quadrado.'),
                Grid::make(2)->schema([
                    TextInput::make('name')
                        ->label('Nome')
                        ->rules($this->nameRules()),
                    TextInput::make('email')
                        ->label('E-mail')
                        ->email()
                        ->rules($this->emailRules($this->getUser()->getKey())),
                    TextInput::make('phone')
                        ->label('Telefone')
                        ->tel()
                        ->placeholder('(11) 99999-9999')
                        ->rules(['nullable', 'string', 'max:30']),
                    TextInput::make('cargo')
                        ->label('Cargo')
                        ->placeholder('Ex.: Analista Financeiro')
                        ->rules(['nullable', 'string', 'max:255']),
                    TextInput::make('departamento')
                        ->label('Departamento')
                        ->placeholder('Ex.: Comercial, Operações, Risco')
                        ->rules(['nullable', 'string', 'max:255']),
                    Textarea::make('bio')
                        ->label('Bio / Descrição curta')
                        ->rows(3)
                        ->maxLength(500)
                        ->rules(['nullable', 'string', 'max:500'])
                        ->columnSpanFull(),
                ]),
                Grid::make(2)->schema([
                    Placeholder::make('role')
                        ->label('Papel na conta')
                        ->content(fn (): string => $this->accountRole()),
                    Placeholder::make('last_login')
                        ->label('Último acesso')
                        ->content(fn (): string => $this->getUser()->last_login_at
                            ? $this->getUser()->last_login_at->format('d/m/Y H:i')
                            : __('Nunca acessado')),
                ]),
            ]);
    }

    public function preferencesForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('preferencesData')
            ->components([
                Grid::make(2)->schema([
                    Select::make('theme')
                        ->label('Tema')
                        ->options(UserInterfaceTheme::options())
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (?string $state): mixed => $this->js(
                            'window.BsiAccountPrefs && window.BsiAccountPrefs.applyTheme('.json_encode($state ?? UserInterfaceTheme::System->value).')'
                        )),
                    Select::make('sidebar_behavior')
                        ->label('Menu lateral')
                        ->options(SidebarBehavior::options())
                        ->required(),
                    Select::make('table_density')
                        ->label('Densidade das tabelas')
                        ->options(TableDensity::options())
                        ->required(),
                    Select::make('per_page')
                        ->label('Registros por página')
                        ->options([
                            10 => '10',
                            25 => '25',
                            50 => '50',
                            100 => '100',
                        ])
                        ->required(),
                    Select::make('home_page')
                        ->label('Página inicial')
                        ->options(UserPreference::homePageOptions($this->getUser()))
                        ->required()
                        ->columnSpanFull(),
                ]),
            ]);
    }

    public function passwordForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('passwordData')
            ->components([
                Grid::make(1)->schema([
                    TextInput::make('current_password')
                        ->label('Senha atual')
                        ->password()
                        ->rules($this->currentPasswordRules()),
                    TextInput::make('password')
                        ->label('Nova senha')
                        ->password()
                        ->rules($this->passwordRules()),
                    TextInput::make('password_confirmation')
                        ->label('Confirmar nova senha')
                        ->password(),
                ]),
            ]);
    }

    public function saveProfile(): void
    {
        $user = $this->getUser();

        $state = $this->profileForm->getState();

        $avatarPath = $this->resolveAvatarPath($state['avatar'] ?? null, $user);

        $user->fill([
            'name' => $state['name'],
            'email' => $state['email'],
            'phone' => $state['phone'] ?? null,
            'cargo' => $state['cargo'] ?? null,
            'departamento' => $state['departamento'] ?? null,
            'bio' => $state['bio'] ?? null,
        ]);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        if ($avatarPath !== $user->avatar_path) {
            $oldPath = $user->avatar_path;
            $user->avatar_path = $avatarPath;

            if (filled($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }
        }

        $user->save();

        $this->profileForm->fill([
            'avatar' => $user->avatar_path,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone ?? '',
            'cargo' => $user->cargo ?? '',
            'departamento' => $user->departamento ?? '',
            'bio' => $user->bio ?? '',
        ]);

        Notification::make()
            ->title('Perfil atualizado com sucesso.')
            ->success()
            ->send();
    }

    public function savePreferences(): void
    {
        $user = $this->getUser();

        $state = $this->preferencesForm->getState();

        $preferences = $user->preferences()->firstOrNew(['user_id' => $user->getKey()]);
        $preferences->fill([
            'theme' => $state['theme'],
            'sidebar_behavior' => $state['sidebar_behavior'],
            'table_density' => $state['table_density'],
            'per_page' => (int) $state['per_page'],
            'home_page' => $state['home_page'],
        ]);

        $changedKeys = array_keys($preferences->getDirty());
        $preferences->save();
        $user->unsetRelation('preferences');

        activity()
            ->causedBy($user)
            ->performedOn($user)
            ->event('account_preferences_updated')
            ->withProperties(['keys' => $changedKeys])
            ->log('Preferências da conta atualizadas.');

        $this->js(
            'window.BsiAccountPrefs && (window.BsiAccountPrefs.applyTheme('.json_encode($preferences->theme->value).'),'
            .'window.BsiAccountPrefs.applyTableDensity('.json_encode($preferences->table_density->value).'),'
            .'window.BsiAccountPrefs.applySidebarBehavior('.json_encode($preferences->sidebar_behavior->value).'))'
        );

        Notification::make()
            ->title('Preferências salvas com sucesso.')
            ->success()
            ->send();
    }

    public function updatePassword(): void
    {
        try {
            $state = $this->passwordForm->getState();
        } catch (ValidationException $e) {
            $this->passwordForm->fill();

            throw $e;
        }

        $user = $this->getUser();
        $user->update(['password' => $state['password']]);

        $this->passwordForm->fill();

        activity()
            ->causedBy($user)
            ->performedOn($user)
            ->event('password_changed')
            ->log('Senha alterada.');

        Notification::make()
            ->title('Senha alterada com sucesso.')
            ->success()
            ->send();
    }

    public function startTwoFactorSetup(): void
    {
        abort_unless(Features::enabled(Features::twoFactorAuthentication()), Response::HTTP_FORBIDDEN);

        $user = $this->getUser()->fresh();

        if ($user->hasEnabledTwoFactorAuthentication()) {
            return;
        }

        app(EnableTwoFactorAuthentication::class)($user);

        $user = $user->fresh();

        $this->twoFactorQrCode = $user->twoFactorQrCodeSvg();
        $this->twoFactorManualKey = decrypt($user->two_factor_secret);
        $this->settingUpTwoFactor = true;
    }

    public function cancelTwoFactorSetup(): void
    {
        $user = $this->getUser()->fresh();

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            app(DisableTwoFactorAuthentication::class)($user);
        }

        $this->resetTwoFactorSetupState();
    }

    public function confirmTwoFactorSetup(ConfirmTwoFactorAuthentication $confirm): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException) {
            Notification::make()
                ->title('Muitas tentativas. Aguarde um minuto e tente novamente.')
                ->danger()
                ->send();

            return;
        }

        $this->validate(['twoFactorCode' => ['required', 'string', 'size:6']]);

        try {
            $confirm($this->getUser()->fresh(), $this->twoFactorCode);
        } catch (ValidationException) {
            $this->addError('twoFactorCode', 'O código informado é inválido. Verifique o aplicativo autenticador e tente novamente.');

            return;
        }

        $this->twoFactorEnabled = true;
        $this->resetTwoFactorSetupState();

        $user = $this->getUser();

        activity()
            ->causedBy($user)
            ->performedOn($user)
            ->event('two_factor_enabled')
            ->log('Autenticação em dois fatores ativada.');

        Notification::make()
            ->title('Autenticação em dois fatores ativada.')
            ->body('Guarde os códigos de recuperação em local seguro.')
            ->success()
            ->send();
    }

    public function disableTwoFactorAction(): Action
    {
        return Action::make('disableTwoFactor')
            ->label('Desativar autenticação em dois fatores')
            ->icon(Heroicon::OutlinedShieldExclamation)
            ->color('danger')
            ->size(Size::Small)
            ->modalHeading('Desativar autenticação em dois fatores?')
            ->modalDescription('Informe sua senha atual para confirmar. Sua conta ficará protegida apenas pela senha.')
            ->form([
                TextInput::make('password')
                    ->label('Senha atual')
                    ->password()
                    ->required()
                    ->rule('current_password'),
            ])
            ->action(function (): void {
                $user = $this->getUser();

                app(DisableTwoFactorAuthentication::class)($user->fresh());

                $this->twoFactorEnabled = false;
                $this->resetTwoFactorSetupState();

                activity()
                    ->causedBy($user)
                    ->performedOn($user)
                    ->event('two_factor_disabled')
                    ->log('Autenticação em dois fatores desativada.');

                Notification::make()
                    ->title('Autenticação em dois fatores desativada.')
                    ->success()
                    ->send();
            });
    }

    public function regenerateRecoveryCodesAction(): Action
    {
        return Action::make('regenerateRecoveryCodes')
            ->label('Gerar novos códigos')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->size(Size::Small)
            ->modalHeading('Gerar novos códigos de recuperação?')
            ->modalDescription('Informe sua senha atual para confirmar. Os códigos anteriores deixarão de funcionar.')
            ->form([
                TextInput::make('password')
                    ->label('Senha atual')
                    ->password()
                    ->required()
                    ->rule('current_password'),
            ])
            ->action(function (): void {
                $user = $this->getUser();

                app(GenerateNewRecoveryCodes::class)($user->fresh());

                unset($this->recoveryCodes);

                activity()
                    ->causedBy($user)
                    ->performedOn($user)
                    ->event('recovery_codes_regenerated')
                    ->log('Códigos de recuperação regenerados.');

                Notification::make()
                    ->title('Novos códigos de recuperação gerados.')
                    ->success()
                    ->send();
            });
    }

    public function revokeSessionAction(): Action
    {
        return Action::make('revokeSession')
            ->label('Encerrar')
            ->color('gray')
            ->size(Size::ExtraSmall)
            ->requiresConfirmation()
            ->modalHeading('Encerrar esta sessão?')
            ->modalDescription('O dispositivo será desconectado imediatamente.')
            ->modalSubmitActionLabel('Encerrar sessão')
            ->action(function (array $arguments): void {
                $user = $this->getUser();
                $sessionId = $arguments['sessionId'] ?? null;

                if (! is_string($sessionId) || $sessionId === '' || $sessionId === session()->getId()) {
                    Notification::make()
                        ->title('Não foi possível encerrar esta sessão.')
                        ->danger()
                        ->send();

                    return;
                }

                $deleted = DB::table($this->sessionTable())
                    ->where('id', $sessionId)
                    ->where('user_id', $user->getKey())
                    ->delete();

                if ($deleted === 0) {
                    Notification::make()
                        ->title('Sessão não encontrada.')
                        ->warning()
                        ->send();

                    return;
                }

                unset($this->activeSessions);

                activity()
                    ->causedBy($user)
                    ->performedOn($user)
                    ->event('session_revoked')
                    ->log('Sessão de outro dispositivo encerrada.');

                Notification::make()
                    ->title('Sessão encerrada.')
                    ->success()
                    ->send();
            });
    }

    public function signOutOtherSessionsAction(): Action
    {
        return Action::make('signOutOtherSessions')
            ->label('Sair das outras sessões')
            ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
            ->color('gray')
            ->size(Size::Small)
            ->modalHeading('Sair das outras sessões?')
            ->modalDescription('Informe sua senha atual para confirmar. Todos os outros dispositivos serão desconectados.')
            ->form([
                TextInput::make('password')
                    ->label('Senha atual')
                    ->password()
                    ->required()
                    ->rule('current_password'),
            ])
            ->action(function (array $data): void {
                $user = $this->getUser();

                Auth::logoutOtherDevices($data['password']);

                $terminated = DB::table($this->sessionTable())
                    ->where('user_id', $user->getKey())
                    ->where('id', '!=', session()->getId())
                    ->delete();

                unset($this->activeSessions);

                activity()
                    ->causedBy($user)
                    ->performedOn($user)
                    ->event('other_sessions_terminated')
                    ->withProperties(['terminated' => $terminated])
                    ->log('Outras sessões encerradas.');

                Notification::make()
                    ->title('Outras sessões encerradas.')
                    ->success()
                    ->send();
            });
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function recoveryCodes(): array
    {
        $user = $this->getUser()->fresh();

        if (! $user->hasEnabledTwoFactorAuthentication() || blank($user->two_factor_recovery_codes)) {
            return [];
        }

        try {
            return json_decode(decrypt($user->two_factor_recovery_codes), true) ?? [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Active database sessions of the current user, newest first.
     *
     * @return list<array{id: string, is_current: bool, device: string, ip_address: ?string, last_active: string}>
     */
    #[Computed]
    public function activeSessions(): array
    {
        if (config('session.driver') !== 'database') {
            return [];
        }

        $currentId = session()->getId();

        return DB::table($this->sessionTable())
            ->where('user_id', $this->getUser()->getKey())
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $row): array => [
                'id' => $row->id,
                'is_current' => $row->id === $currentId,
                'device' => $this->describeUserAgent($row->user_agent),
                'ip_address' => $row->ip_address,
                'last_active' => Carbon::createFromTimestamp($row->last_activity)->diffForHumans(),
            ])
            ->all();
    }

    public function sessionsSupported(): bool
    {
        return config('session.driver') === 'database';
    }

    public function accountRole(): string
    {
        $user = $this->getUser();

        if (filled($user->cargo)) {
            return $user->cargo;
        }

        if ($user->hasRole(['super-admin', 'admin'])) {
            return __('Administrador');
        }

        if ($user->hasRole('editor')) {
            return __('Operações');
        }

        if ($user->hasRole('commercial-representative')) {
            return __('Comercial');
        }

        return __('BSI Capital');
    }

    protected function getUser(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, Response::HTTP_FORBIDDEN);

        return $user;
    }

    protected function sessionTable(): string
    {
        return (string) config('session.table', 'sessions');
    }

    protected function resetTwoFactorSetupState(): void
    {
        $this->twoFactorCode = '';
        $this->twoFactorQrCode = '';
        $this->twoFactorManualKey = '';
        $this->settingUpTwoFactor = false;

        $this->resetErrorBag();
    }

    /**
     * Validate the submitted avatar path against path-forgery.
     *
     * Filament passes existing file paths through unchanged, and that string
     * is client-controllable — so only the user's own current path or a fresh
     * upload inside the user's own directory is accepted.
     */
    protected function resolveAvatarPath(mixed $submitted, User $user): ?string
    {
        if (is_array($submitted)) {
            // A fresh upload may arrive alongside the current path; the new
            // file always wins, while an unchanged selection keeps the path.
            $candidates = array_values(array_filter(
                $submitted,
                fn (mixed $value): bool => $value !== $user->avatar_path,
            ));

            $submitted = count($candidates) > 0 ? reset($candidates) : $user->avatar_path;
        }

        if (blank($submitted)) {
            return null;
        }

        if (! is_string($submitted)) {
            $this->rejectAvatarPath();

            return null;
        }

        if ($submitted === $user->avatar_path) {
            return $submitted;
        }

        $prefix = 'avatars/'.$user->getKey().'/';

        if (! str_starts_with($submitted, $prefix)) {
            $this->rejectAvatarPath();

            return null;
        }

        $fileName = Str::after($submitted, $prefix);

        if (! preg_match('/^[A-Za-z0-9]{26}\.(jpe?g|png|webp)$/i', $fileName)) {
            $this->rejectAvatarPath();

            return null;
        }

        if (! Storage::disk('public')->exists($submitted)) {
            $this->rejectAvatarPath();

            return null;
        }

        return $submitted;
    }

    protected function rejectAvatarPath(): void
    {
        throw ValidationException::withMessages([
            'profileData.avatar' => 'O arquivo enviado é inválido.',
        ]);
    }

    /**
     * Best-effort device label from a user agent string, without inventing data.
     */
    protected function describeUserAgent(?string $userAgent): string
    {
        if (blank($userAgent)) {
            return 'Sessão web';
        }

        $os = match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Mac OS X') => 'macOS',
            str_contains($userAgent, 'CrOS') => 'ChromeOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };

        $parts = array_filter([$os, $browser]);

        return $parts === [] ? 'Sessão web' : implode(' · ', $parts);
    }
}
