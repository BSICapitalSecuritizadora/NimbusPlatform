<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Perfil --}}
        <x-filament::section
            :heading="__('Perfil')"
            :description="__('Gerencie suas informações pessoais, foto e dados profissionais.')"
            :icon="\Filament\Support\Icons\Heroicon::OutlinedUser"
        >
            <form wire:submit="saveProfile" class="space-y-6">
                {{ $this->profileForm }}

                <div class="flex items-center justify-end gap-3">
                    <x-filament::button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="saveProfile"
                        data-test="my-account-save-profile"
                    >
                        {{ __('Salvar perfil') }}
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>

        {{-- Personalização --}}
        <x-filament::section
            :heading="__('Personalização')"
            :description="__('Ajuste o comportamento da interface. A identidade visual institucional é mantida.')"
            :icon="\Filament\Support\Icons\Heroicon::OutlinedAdjustmentsHorizontal"
        >
            <form wire:submit="savePreferences" class="space-y-6">
                {{ $this->preferencesForm }}

                <div class="flex items-center justify-end gap-3">
                    <x-filament::button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="savePreferences"
                        data-test="my-account-save-preferences"
                    >
                        {{ __('Salvar preferências') }}
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>

        {{-- Segurança --}}
        <x-filament::section
            :heading="__('Segurança')"
            :description="__('Proteja sua conta com senha forte, autenticação em dois fatores e controle de sessões.')"
            :icon="\Filament\Support\Icons\Heroicon::OutlinedShieldCheck"
        >
            <div class="space-y-8">
                {{-- Senha --}}
                <div>
                    <h3 class="fi-section-header-heading text-sm font-semibold">{{ __('Senha') }}</h3>
                    <p class="fi-section-header-description mt-1 text-sm">{{ __('Utilize uma senha forte e única para proteger o acesso à sua conta.') }}</p>

                    <form wire:submit="updatePassword" class="mt-4 max-w-xl space-y-6">
                        {{ $this->passwordForm }}

                        <div class="flex items-center justify-start gap-3">
                            <x-filament::button
                                type="submit"
                                wire:loading.attr="disabled"
                                wire:target="updatePassword"
                                data-test="my-account-save-password"
                            >
                                {{ __('Alterar senha') }}
                            </x-filament::button>
                        </div>
                    </form>
                </div>

                <div class="border-t border-gray-200 dark:border-white/10"></div>

                {{-- 2FA --}}
                <div>
                    <div class="flex flex-wrap items-center gap-3">
                        <h3 class="fi-section-header-heading text-sm font-semibold">{{ __('Autenticação em dois fatores') }}</h3>

                        @if ($twoFactorEnabled)
                            <x-filament::badge color="success">{{ __('Habilitada') }}</x-filament::badge>
                        @else
                            <x-filament::badge color="danger">{{ __('Desabilitada') }}</x-filament::badge>
                        @endif
                    </div>

                    @if ($twoFactorEnabled)
                        <p class="fi-section-header-description mt-1 max-w-2xl text-sm">
                            {{ __('Com a autenticação em dois fatores habilitada, você deverá informar um código de segurança a cada acesso, gerado pelo aplicativo autenticador configurado no seu dispositivo.') }}
                        </p>

                        <div class="mt-4 max-w-2xl">
                            <h4 class="text-sm font-medium">{{ __('Códigos de recuperação') }}</h4>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                {{ __('Os códigos de recuperação permitem o acesso à sua conta caso você perca o dispositivo autenticador. Armazene-os em local seguro.') }}
                            </p>

                            @if (count($this->recoveryCodes) > 0)
                                <div
                                    class="mt-3 grid grid-cols-1 gap-2 rounded-xl border border-gray-200 bg-gray-50 p-4 sm:grid-cols-2 dark:border-white/10 dark:bg-white/5"
                                    x-data="{ copied: false }"
                                >
                                    @foreach ($this->recoveryCodes as $code)
                                        <code wire:key="recovery-code-{{ $loop->index }}" class="font-mono text-sm">{{ $code }}</code>
                                    @endforeach

                                    <div class="col-span-full mt-1 flex justify-end">
                                        <x-filament::button
                                            color="gray"
                                            size="xs"
                                            icon="heroicon-o-clipboard-document"
                                            x-on:click="navigator.clipboard.writeText(@js(implode("\n", $this->recoveryCodes))); copied = true; setTimeout(() => copied = false, 1500)"
                                        >
                                            <span x-show="! copied">{{ __('Copiar códigos') }}</span>
                                            <span x-show="copied" x-cloak>{{ __('Copiado!') }}</span>
                                        </x-filament::button>
                                    </div>
                                </div>
                            @endif

                            <div class="mt-4 flex flex-wrap gap-3">
                                {{ $this->getAction('regenerateRecoveryCodes') }}
                                {{ $this->getAction('disableTwoFactor') }}
                            </div>
                        </div>
                    @elseif ($settingUpTwoFactor)
                        <p class="fi-section-header-description mt-1 max-w-2xl text-sm">
                            {{ __('Escaneie o QR Code abaixo com seu aplicativo autenticador e informe o código de 6 dígitos para concluir a ativação.') }}
                        </p>

                        <div class="mt-4 flex max-w-2xl flex-col gap-6 sm:flex-row">
                            <div class="shrink-0">
                                <div class="rounded-xl border border-gray-200 bg-white p-3 dark:border-white/10">
                                    {!! $twoFactorQrCode !!}
                                </div>
                                <p class="mt-2 max-w-56 break-all text-xs text-gray-500 dark:text-gray-400">
                                    {{ __('Chave manual:') }} <code class="font-mono">{{ $twoFactorManualKey }}</code>
                                </p>
                            </div>

                            <div class="w-full max-w-xs space-y-4">
                                <div>
                                    <label for="twoFactorCode" class="fi-fo-field-label-content mb-2 block text-sm font-medium">
                                        {{ __('Código de verificação') }}
                                    </label>
                                    <x-filament::input
                                        id="twoFactorCode"
                                        type="text"
                                        inputmode="numeric"
                                        autocomplete="one-time-code"
                                        maxlength="6"
                                        placeholder="123456"
                                        wire:model="twoFactorCode"
                                    />
                                    @error('twoFactorCode')
                                        <p class="mt-2 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="flex gap-3">
                                    <x-filament::button
                                        color="gray"
                                        wire:click="cancelTwoFactorSetup"
                                    >
                                        {{ __('Cancelar') }}
                                    </x-filament::button>
                                    <x-filament::button
                                        wire:click="confirmTwoFactorSetup"
                                        wire:loading.attr="disabled"
                                        data-test="my-account-confirm-2fa"
                                    >
                                        {{ __('Confirmar ativação') }}
                                    </x-filament::button>
                                </div>
                            </div>
                        </div>
                    @else
                        <p class="fi-section-header-description mt-1 max-w-2xl text-sm">
                            {{ __('Ao habilitar a autenticação em dois fatores, você precisará informar um código de segurança a cada acesso. O código é gerado por um aplicativo autenticador compatível com TOTP no seu dispositivo.') }}
                        </p>

                        <div class="mt-4">
                            <x-filament::button
                                wire:click="startTwoFactorSetup"
                                icon="heroicon-o-shield-check"
                                data-test="my-account-enable-2fa"
                            >
                                {{ __('Ativar autenticação em dois fatores') }}
                            </x-filament::button>
                        </div>
                    @endif
                </div>

                <div class="border-t border-gray-200 dark:border-white/10"></div>

                {{-- Sessões --}}
                <div>
                    <h3 class="fi-section-header-heading text-sm font-semibold">{{ __('Sessões ativas') }}</h3>
                    <p class="fi-section-header-description mt-1 max-w-2xl text-sm">
                        {{ __('Dispositivos conectados à sua conta. Encerre sessões que você não reconheça.') }}
                    </p>

                    @if (! $this->sessionsSupported())
                        <p class="mt-4 max-w-2xl text-sm text-gray-500 dark:text-gray-400">
                            {{ __('O gerenciamento de sessões não está disponível com o driver de sessão atual.') }}
                        </p>
                    @else
                        <ul class="mt-4 max-w-2xl divide-y divide-gray-200 rounded-xl border border-gray-200 dark:divide-white/10 dark:border-white/10">
                            @forelse ($this->activeSessions as $session)
                                <li wire:key="session-{{ $session['id'] }}" class="flex items-center justify-between gap-4 px-4 py-3">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium">
                                            {{ $session['device'] }}
                                            @if ($session['is_current'])
                                                <x-filament::badge color="success" class="ms-2">{{ __('Este dispositivo') }}</x-filament::badge>
                                            @endif
                                        </p>
                                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                            {{ $session['last_active'] }}
                                            @if (filled($session['ip_address']))
                                                · {{ $session['ip_address'] }}
                                            @endif
                                        </p>
                                    </div>

                                    @unless ($session['is_current'])
                                        <x-filament::button
                                            color="gray"
                                            size="xs"
                                            wire:click="mountAction('revokeSession', { sessionId: '{{ $session['id'] }}' })"
                                        >
                                            {{ __('Encerrar') }}
                                        </x-filament::button>
                                    @endunless
                                </li>
                            @empty
                                <li class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                                    {{ __('Nenhuma sessão registrada.') }}
                                </li>
                            @endforelse
                        </ul>

                        @if (count($this->activeSessions) > 1)
                            <div class="mt-4">
                                {{ $this->getAction('signOutOtherSessions') }}
                            </div>
                        @endif
                    @endif
                </div>
            </div>
        </x-filament::section>
    </div>

</x-filament-panels::page>
