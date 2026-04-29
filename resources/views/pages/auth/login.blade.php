<x-layouts::auth :title="__('Acesso ao Portal')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Acesse sua conta')" :description="__('Use sua conta corporativa Microsoft 365')" />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <flux:button
            variant="primary"
            as="a"
            href="{{ route('auth.azure.redirect') }}"
            class="w-full"
            data-test="microsoft-login-button"
        >
            {{ __('Entrar com Microsoft 365') }}
        </flux:button>

        <p class="text-center text-sm text-zinc-600 dark:text-zinc-400">
            {{ __('O acesso com senha local está desativado para usuários administrativos.') }}
        </p>
    </div>
</x-layouts::auth>
