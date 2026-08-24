<?php

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Meu Perfil')] class extends Component {
    use ProfileValidationRules, WithFileUploads;

    public string $name = '';
    public string $email = '';
    public string $cargo = '';
    public string $departamento = '';
    public string $phone = '';
    public string $bio = '';

    public $avatar = null;

    public bool $confirmingAvatarRemoval = false;

    public function mount(): void
    {
        $user = Auth::user();

        $this->name = $user->name;
        $this->email = $user->email;
        $this->cargo = $user->cargo ?? '';
        $this->departamento = $user->departamento ?? '';
        $this->phone = $user->phone ?? '';
        $this->bio = $user->bio ?? '';
    }

    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            ...$this->profileRules($user->id),
            'cargo' => ['nullable', 'string', 'max:255'],
            'departamento' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'bio' => ['nullable', 'string', 'max:500'],
            'avatar' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048', 'dimensions:min_width=80,min_height=80,max_width=4000,max_height=4000'],
        ]);

        $user->fill(collect($validated)->except('avatar')->toArray());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        if ($this->avatar) {
            $oldPath = $user->avatar_path;

            $path = $this->avatar->store('avatars/'.$user->id, 'public');

            $user->avatar_path = $path;

            if ($oldPath) {
                Storage::disk('public')->delete($oldPath);
            }
        }

        $user->save();

        $this->avatar = null;

        $this->dispatch('profile-updated', name: $user->name);
    }

    public function removeAvatar(): void
    {
        $user = Auth::user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->avatar_path = null;
            $user->save();
        }

        $this->avatar = null;
        $this->confirmingAvatarRemoval = false;

        $this->dispatch('profile-updated', name: $user->name);
    }

    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return Auth::user() instanceof MustVerifyEmail && ! Auth::user()->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        return ! Auth::user() instanceof MustVerifyEmail
            || (Auth::user() instanceof MustVerifyEmail && Auth::user()->hasVerifiedEmail());
    }

    #[Computed]
    public function avatarPreviewUrl(): ?string
    {
        if ($this->avatar) {
            try {
                if (method_exists($this->avatar, 'isPreviewable') && ! $this->avatar->isPreviewable()) {
                    return Auth::user()->avatarUrl();
                }

                return $this->avatar->temporaryUrl();
            } catch (\Throwable) {
                return Auth::user()->avatarUrl();
            }
        }

        return Auth::user()->avatarUrl();
    }

    #[Computed]
    public function hasExistingAvatar(): bool
    {
        return filled(Auth::user()->avatar_path);
    }

    #[Computed]
    public function accountRole(): string
    {
        $user = Auth::user();

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
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Meu Perfil') }}</flux:heading>

    <div class="flex items-start max-md:flex-col">
        <div class="me-10 w-full pb-4 md:w-[220px]">
            <flux:navlist aria-label="{{ __('Configurações') }}">
                <flux:navlist.item :href="route('profile.edit')" wire:navigate current>{{ __('Perfil') }}</flux:navlist.item>
                @if (Laravel\Fortify\Features::canManageTwoFactorAuthentication())
                    <flux:navlist.item :href="route('two-factor.show')" wire:navigate>{{ __('Autenticação em dois fatores') }}</flux:navlist.item>
                @endif
                <flux:navlist.item :href="route('appearance.edit')" wire:navigate>{{ __('Aparência') }}</flux:navlist.item>
            </flux:navlist>
        </div>

        <flux:separator class="md:hidden" />

        <div class="flex-1 self-stretch max-md:pt-6">
            <flux:heading size="lg">{{ __('Meu Perfil') }}</flux:heading>
            <flux:subheading>{{ __('Gerencie suas informações pessoais, foto e dados profissionais') }}</flux:subheading>

            <div class="mt-6 space-y-6">
                {{-- Profile — Avatar and basic personal information --}}
                <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-white/10 dark:bg-zinc-900">
                    <div class="border-b border-zinc-200 bg-zinc-50/60 px-6 py-4 dark:border-white/10 dark:bg-white/[0.03]">
                        <div class="flex items-center gap-3">
                            <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-zinc-900 text-white dark:bg-white dark:text-zinc-900">
                                <flux:icon name="user" variant="outline" class="h-5 w-5" />
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Perfil') }}</h3>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Foto e informações básicas da sua conta') }}</p>
                            </div>
                        </div>
                    </div>

                    <form wire:submit="updateProfileInformation" class="space-y-6 px-6 py-6">
                        {{-- Avatar section --}}
                        <div class="flex flex-col gap-6 sm:flex-row sm:items-start">
                            <div class="flex shrink-0 flex-col items-center gap-3">
                                <div class="relative">
                                    @if ($this->avatarPreviewUrl)
                                        <img src="{{ $this->avatarPreviewUrl }}" alt="{{ auth()->user()->name }}" class="h-24 w-24 rounded-full object-cover ring-1 ring-zinc-200 dark:ring-white/15" />
                                    @else
                                        <div class="flex h-24 w-24 items-center justify-center rounded-full bg-zinc-900 text-xl font-semibold text-white ring-1 ring-zinc-200 dark:bg-white dark:text-zinc-900 dark:ring-white/15">
                                            {{ auth()->user()->initials() }}
                                        </div>
                                    @endif
                                    <div wire:loading wire:target="avatar" class="absolute inset-0 flex items-center justify-center rounded-full bg-white/70 dark:bg-zinc-900/70">
                                        <flux:icon name="arrow-path" class="h-6 w-6 animate-spin text-zinc-600 dark:text-zinc-300" />
                                    </div>
                                </div>
                                <div class="text-center">
                                    <p class="text-xs font-medium text-zinc-900 dark:text-white">{{ auth()->user()->name }}</p>
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $this->accountRole }}</p>
                                </div>
                            </div>

                            <div class="flex-1 space-y-4">
                                <div>
                                    <flux:field>
                                        <flux:label>{{ __('Foto de perfil') }}</flux:label>
                                        <flux:description class="mb-2">{{ __('JPG, PNG ou WebP. Máximo 2 MB. Mínimo 80×80px.') }}</flux:description>
                                        <input
                                            type="file"
                                            wire:model.live="avatar"
                                            accept="image/jpeg,image/png,image/webp"
                                            class="block w-full rounded-xl border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-700 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-900 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-white hover:file:bg-zinc-800 dark:border-white/10 dark:bg-zinc-800 dark:text-zinc-200 dark:file:bg-white dark:file:text-zinc-900"
                                        />
                                        <flux:error name="avatar" />
                                        <div wire:loading wire:target="avatar" class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Enviando imagem...') }}</div>
                                        @if ($avatar)
                                            <flux:text class="mt-2 text-xs text-emerald-600 dark:text-emerald-400">{{ __('Prévia carregada. Clique em Salvar para confirmar.') }}</flux:text>
                                        @endif
                                    </flux:field>
                                </div>

                                <div class="flex flex-wrap gap-2">
                                    @if ($this->hasExistingAvatar || $avatar)
                                        <flux:button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            wire:click="$set('confirmingAvatarRemoval', true)"
                                            icon="trash"
                                        >
                                            {{ __('Remover foto') }}
                                        </flux:button>
                                    @endif
                                    @if ($avatar)
                                        <flux:button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            wire:click="$set('avatar', null)"
                                            icon="x-mark"
                                        >
                                            {{ __('Cancelar seleção') }}
                                        </flux:button>
                                    @endif
                                </div>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('A imagem será exibida no menu do usuário e em formato circular em toda a interface.') }}</p>
                            </div>
                        </div>

                        <flux:separator />

                        <div class="grid gap-5 sm:grid-cols-2">
                            <flux:field>
                                <flux:label>{{ __('Nome') }} <span class="text-red-500">*</span></flux:label>
                                <flux:input wire:model="name" type="text" required autocomplete="name" placeholder="{{ __('Seu nome completo') }}" />
                                <flux:error name="name" />
                            </flux:field>

                            <flux:field>
                                <flux:label>{{ __('E-mail') }} <span class="text-red-500">*</span></flux:label>
                                <flux:input wire:model="email" type="email" required autocomplete="email" />
                                <flux:error name="email" />
                                <flux:description>{{ __('Usado para login e notificações') }}</flux:description>
                                @if ($this->hasUnverifiedEmail)
                                    <div class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 dark:border-amber-800/40 dark:bg-amber-950/30">
                                        <flux:text class="text-xs text-amber-800 dark:text-amber-200">
                                            {{ __('Seu endereço de e-mail não foi verificado.') }}
                                            <button type="button" wire:click.prevent="resendVerificationNotification" class="font-semibold underline underline-offset-2 hover:text-amber-900 dark:hover:text-amber-100">{{ __('Reenviar verificação') }}</button>
                                        </flux:text>
                                        @if (session('status') === 'verification-link-sent')
                                            <flux:text class="mt-1 text-xs font-medium text-emerald-700 dark:text-emerald-300">{{ __('Link de verificação enviado para seu e-mail.') }}</flux:text>
                                        @endif
                                    </div>
                                @endif
                            </flux:field>
                        </div>

                        <div class="flex items-center gap-3 pt-2">
                            <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="updateProfileInformation,avatar" icon="check" data-test="update-profile-button">
                                <span wire:loading.remove wire:target="updateProfileInformation">{{ __('Salvar perfil') }}</span>
                                <span wire:loading wire:target="updateProfileInformation">{{ __('Salvando...') }}</span>
                            </flux:button>
                            <x-action-message class="me-3" on="profile-updated">
                                {{ __('Salvo.') }}
                            </x-action-message>
                        </div>
                    </form>
                </div>

                {{-- Professional Information --}}
                <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-white/10 dark:bg-zinc-900">
                    <div class="border-b border-zinc-200 bg-zinc-50/60 px-6 py-4 dark:border-white/10 dark:bg-white/[0.03]">
                        <div class="flex items-center gap-3">
                            <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-zinc-900 text-white dark:bg-white dark:text-zinc-900">
                                <flux:icon name="briefcase" variant="outline" class="h-5 w-5" />
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Informações profissionais') }}</h3>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Cargo, departamento, contato e apresentação') }}</p>
                            </div>
                        </div>
                    </div>

                    <form wire:submit="updateProfileInformation" class="space-y-5 px-6 py-6">
                        <div class="grid gap-5 sm:grid-cols-2">
                            <flux:field>
                                <flux:label>{{ __('Cargo') }}</flux:label>
                                <flux:input wire:model="cargo" type="text" autocomplete="organization-title" placeholder="{{ __('Ex.: Analista Financeiro') }}" />
                                <flux:error name="cargo" />
                                <flux:description>{{ __('Exibido no menu do usuário quando preenchido') }}</flux:description>
                            </flux:field>

                            <flux:field>
                                <flux:label>{{ __('Departamento') }}</flux:label>
                                <flux:input wire:model="departamento" type="text" placeholder="{{ __('Ex.: Comercial, Operações, Risco') }}" />
                                <flux:error name="departamento" />
                            </flux:field>
                        </div>

                        <flux:field>
                            <flux:label>{{ __('Telefone') }}</flux:label>
                            <flux:input wire:model="phone" type="tel" autocomplete="tel" placeholder="{{ __('Ex.: (11) 99999-9999') }}" />
                            <flux:error name="phone" />
                            <flux:description>{{ __('Opcional. Apenas para uso interno do perfil') }}</flux:description>
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Bio / Descrição curta') }}</flux:label>
                            <flux:textarea wire:model="bio" rows="3" placeholder="{{ __('Uma breve descrição sobre você, ex.: responsabilidades, especialidades...') }}" />
                            <flux:error name="bio" />
                            <flux:description>{{ __('Máximo 500 caracteres. Exibido apenas no seu perfil') }} — <span x-text="($wire.bio ?? '').length + ' / 500'"></span></flux:description>
                        </flux:field>

                        <div class="flex items-center gap-3 pt-2">
                            <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="updateProfileInformation" icon="check">
                                <span wire:loading.remove wire:target="updateProfileInformation">{{ __('Salvar informações profissionais') }}</span>
                                <span wire:loading wire:target="updateProfileInformation">{{ __('Salvando...') }}</span>
                            </flux:button>
                            <x-action-message class="me-3" on="profile-updated">
                                {{ __('Salvo.') }}
                            </x-action-message>
                        </div>
                    </form>
                </div>

                {{-- Account — read-only --}}
                <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-white/10 dark:bg-zinc-900">
                    <div class="border-b border-zinc-200 bg-zinc-50/60 px-6 py-4 dark:border-white/10 dark:bg-white/[0.03]">
                        <div class="flex items-center gap-3">
                            <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-zinc-900 text-white dark:bg-white dark:text-zinc-900">
                                <flux:icon name="shield-check" variant="outline" class="h-5 w-5" />
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Conta') }}</h3>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Informações da conta gerenciadas pelo sistema') }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-4 px-6 py-6">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-white/10 dark:bg-white/[0.03]">
                                <p class="text-xs font-medium uppercase tracking-widest text-zinc-500 dark:text-zinc-400">{{ __('E-mail da conta') }}</p>
                                <p class="mt-1 truncate text-sm font-medium text-zinc-900 dark:text-white">{{ auth()->user()->email }}</p>
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    @if (auth()->user()->email_verified_at)
                                        {{ __('Verificado em') }} {{ auth()->user()->email_verified_at->format('d/m/Y') }}
                                    @else
                                        {{ __('Não verificado') }}
                                    @endif
                                </p>
                            </div>

                            <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-white/10 dark:bg-white/[0.03]">
                                <p class="text-xs font-medium uppercase tracking-widest text-zinc-500 dark:text-zinc-400">{{ __('Status') }}</p>
                                <div class="mt-1 flex items-center gap-2">
                                    @if (auth()->user()->is_active && auth()->user()->isApproved())
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 ring-1 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-400/30">
                                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-600 dark:bg-emerald-400"></span>{{ __('Ativo') }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700 ring-1 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300">
                                            {{ __('Pendente') }}
                                        </span>
                                    @endif
                                </div>
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ __('Membro desde') }} {{ auth()->user()->created_at?->format('d/m/Y') ?? '—' }}
                                </p>
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="rounded-xl border border-zinc-200 px-4 py-3 dark:border-white/10">
                                <p class="text-xs font-medium uppercase tracking-widest text-zinc-500 dark:text-zinc-400">{{ __('Papéis') }}</p>
                                <div class="mt-2 flex flex-wrap gap-1.5">
                                    @forelse (auth()->user()->getRoleNames() as $role)
                                        <span class="inline-flex rounded-full bg-zinc-900 px-2.5 py-1 text-xs font-medium text-white dark:bg-white dark:text-zinc-900">{{ $role }}</span>
                                    @empty
                                        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Nenhum papel atribuído') }}</span>
                                    @endforelse
                                </div>
                            </div>
                            <div class="rounded-xl border border-zinc-200 px-4 py-3 dark:border-white/10">
                                <p class="text-xs font-medium uppercase tracking-widest text-zinc-500 dark:text-zinc-400">{{ __('Último acesso') }}</p>
                                <p class="mt-1 text-sm text-zinc-900 dark:text-white">
                                    {{ auth()->user()->last_login_at ? auth()->user()->last_login_at->format('d/m/Y H:i') : __('Nunca acessado') }}
                                </p>
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Gerenciado automaticamente pelo sistema') }}</p>
                            </div>
                        </div>

                        <div class="rounded-lg bg-zinc-50 px-4 py-3 text-xs leading-5 text-zinc-600 dark:bg-white/[0.03] dark:text-zinc-400">
                            <flux:icon name="information-circle" variant="micro" class="inline h-4 w-4 text-zinc-400" />
                            {{ __('Para alterar senha, autenticação em dois fatores ou aparência, use as abas ao lado.') }}
                        </div>
                    </div>
                </div>

                @if ($this->showDeleteUser)
                    <livewire:pages::settings.delete-user-form />
                @endif
            </div>
        </div>
    </div>

    {{-- Remove avatar confirmation --}}
    <flux:modal wire:model="confirmingAvatarRemoval" class="max-w-md">
        <div class="space-y-4">
            <div class="flex h-10 w-10 items-center justify-center rounded-full bg-red-50 dark:bg-red-500/10">
                <flux:icon name="trash" class="h-5 w-5 text-red-600 dark:text-red-400" />
            </div>
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('Remover foto de perfil?') }}</flux:heading>
                <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                    {{ __('Sua foto será removida e o avatar voltará a exibir suas iniciais, como') }} <span class="font-semibold text-zinc-900 dark:text-white">{{ auth()->user()->initials() }}</span>.
                    {{ __('Esta ação não pode ser desfeita, mas você poderá enviar uma nova imagem a qualquer momento.') }}
                </flux:text>
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <flux:button variant="ghost" wire:click="$set('confirmingAvatarRemoval', false)">{{ __('Cancelar') }}</flux:button>
                <flux:button variant="danger" wire:click="removeAvatar" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="removeAvatar">{{ __('Remover') }}</span>
                    <span wire:loading wire:target="removeAvatar">{{ __('Removendo...') }}</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
