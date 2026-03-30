<x-layouts::auth :title="__('Recuperação de senha')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Recuperação de senha')" :description="__('Informe seu e-mail para receber as instruções de redefinição de senha')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-6">
            @csrf

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('E-mail')"
                type="email"
                required
                autofocus
                placeholder="email@empresa.com.br"
            />

            <flux:button variant="primary" type="submit" class="w-full" data-test="email-password-reset-link-button">
                {{ __('Enviar link de recuperação') }}
            </flux:button>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-400">
            <span>{{ __('Ou volte para') }}</span>
            <flux:link :href="route('login')" wire:navigate>{{ __('acessar o portal') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
