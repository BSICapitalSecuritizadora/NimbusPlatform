<x-layouts::auth :title="__('Confirmação de identidade')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Confirmação de identidade')"
            :description="__('Esta é uma área restrita do sistema. Confirme sua senha para prosseguir.')"
        />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.confirm.store') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="password"
                :label="__('Senha')"
                type="password"
                required
                autocomplete="current-password"
                :placeholder="__('Senha')"
                viewable
            />

            <flux:button variant="primary" type="submit" class="w-full" data-test="confirm-password-button">
                {{ __('Confirmar acesso') }}
            </flux:button>
        </form>
    </div>
</x-layouts::auth>
