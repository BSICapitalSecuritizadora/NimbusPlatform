<x-layouts::auth :title="__('Cadastro')">
    <div class="flex flex-col gap-6">
        @php
            $invitationToken = request('token') ?: old('invitation_token');
        @endphp

        @if (! $invitationToken)
            <x-auth-header
                :title="__('Acesso restrito')"
                :description="__('O cadastro neste portal é realizado exclusivamente por convite.')"
            />

            <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                <p class="font-semibold">{{ __('Cadastro por convite') }}</p>
                <p class="mt-1">{{ __('O acesso ao portal BSI Capital é restrito a usuários convidados. Caso você deva ter acesso, entre em contato com a equipe responsável para receber um convite.') }}</p>
            </div>

            <div class="text-center text-sm text-zinc-600 dark:text-zinc-400">
                <flux:link :href="route('login')" wire:navigate>{{ __('Acessar portal com uma conta existente') }}</flux:link>
            </div>
        @else
            <x-auth-header :title="__('Criar conta')" :description="__('Preencha os dados abaixo para concluir seu cadastro')" />

            <!-- Session Status -->
            <x-auth-session-status class="text-center" :status="session('status')" />

            <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-6">
                @csrf

                <!-- Invitation token (hidden) -->
                <input type="hidden" name="invitation_token" value="{{ $invitationToken }}">

                <!-- Name -->
                <flux:input
                    name="name"
                    :label="__('Nome completo')"
                    :value="old('name')"
                    type="text"
                    required
                    autofocus
                    autocomplete="name"
                    :placeholder="__('Nome completo')"
                />

                <!-- Email Address -->
                <flux:input
                    name="email"
                    :label="__('E-mail')"
                    :value="old('email')"
                    type="email"
                    required
                    autocomplete="email"
                    placeholder="email@empresa.com.br"
                />

                <!-- Cargo -->
                <flux:input
                    name="cargo"
                    :label="__('Cargo')"
                    :value="old('cargo')"
                    type="text"
                    autocomplete="organization-title"
                    :placeholder="__('Ex.: Analista Financeiro')"
                />

                <!-- Departamento -->
                <flux:input
                    name="departamento"
                    :label="__('Departamento')"
                    :value="old('departamento')"
                    type="text"
                    :placeholder="__('Ex.: Comercial')"
                />

                <!-- Password with strength indicator -->
                <div
                    x-data="{
                        password: '',
                        reqs: {
                            length: false,
                            casing: false,
                            number: false,
                            special: false,
                        },
                        get strength() {
                            const met = Object.values(this.reqs).filter(Boolean).length;
                            return met;
                        },
                        get strengthLabel() {
                            const labels = ['', 'Fraca', 'Regular', 'Forte', 'Muito forte'];
                            return labels[this.strength] ?? '';
                        },
                        get strengthColor() {
                            const colors = ['', 'bg-red-500', 'bg-amber-400', 'bg-blue-500', 'bg-green-500'];
                            return colors[this.strength] ?? '';
                        },
                        checkStrength(val) {
                            this.reqs.length  = val.length >= 8;
                            this.reqs.casing  = /[a-z]/.test(val) && /[A-Z]/.test(val);
                            this.reqs.number  = /[0-9]/.test(val);
                            this.reqs.special = /[^a-zA-Z0-9]/.test(val);
                        },
                    }"
                    class="flex flex-col gap-2"
                >
                    <flux:input
                        name="password"
                        :label="__('Senha')"
                        type="password"
                        required
                        autocomplete="new-password"
                        :placeholder="__('Senha')"
                        viewable
                        x-model="password"
                        x-on:input="checkStrength($event.target.value)"
                    />

                    <!-- Strength bar -->
                    <div x-show="password.length > 0" class="flex flex-col gap-1.5">
                        <div class="flex gap-1">
                            <template x-for="i in 4" :key="i">
                                <div
                                    class="h-1.5 flex-1 rounded-full transition-colors duration-300"
                                    :class="i <= strength ? strengthColor : 'bg-zinc-200 dark:bg-zinc-700'"
                                ></div>
                            </template>
                        </div>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400" x-text="strengthLabel"></p>
                    </div>

                    <!-- Requirements checklist -->
                    <div x-show="password.length > 0" class="rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-900">
                        <p class="mb-2 text-xs font-semibold text-zinc-600 dark:text-zinc-400">{{ __('Requisitos da senha:') }}</p>
                        <ul class="space-y-1 text-xs">
                            <li class="flex items-center gap-1.5" :class="reqs.length ? 'text-green-600 dark:text-green-400' : 'text-zinc-400 dark:text-zinc-500'">
                                <span x-text="reqs.length ? '✓' : '○'"></span>
                                {{ __('Mínimo 8 caracteres') }}
                            </li>
                            <li class="flex items-center gap-1.5" :class="reqs.casing ? 'text-green-600 dark:text-green-400' : 'text-zinc-400 dark:text-zinc-500'">
                                <span x-text="reqs.casing ? '✓' : '○'"></span>
                                {{ __('Letras maiúsculas e minúsculas') }}
                            </li>
                            <li class="flex items-center gap-1.5" :class="reqs.number ? 'text-green-600 dark:text-green-400' : 'text-zinc-400 dark:text-zinc-500'">
                                <span x-text="reqs.number ? '✓' : '○'"></span>
                                {{ __('Ao menos um número') }}
                            </li>
                            <li class="flex items-center gap-1.5" :class="reqs.special ? 'text-green-600 dark:text-green-400' : 'text-zinc-400 dark:text-zinc-500'">
                                <span x-text="reqs.special ? '✓' : '○'"></span>
                                {{ __('Ao menos um caractere especial') }}
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Confirm Password -->
                <flux:input
                    name="password_confirmation"
                    :label="__('Confirmar senha')"
                    type="password"
                    required
                    autocomplete="new-password"
                    :placeholder="__('Confirmar senha')"
                    viewable
                />

                <div class="flex items-center justify-end">
                    <flux:button type="submit" variant="primary" class="w-full" data-test="register-user-button">
                        {{ __('Criar conta') }}
                    </flux:button>
                </div>
            </form>

            <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
                <span>{{ __('Já possui cadastro?') }}</span>
                <flux:link :href="route('login')" wire:navigate>{{ __('Acessar portal') }}</flux:link>
            </div>
        @endif
    </div>
</x-layouts::auth>
