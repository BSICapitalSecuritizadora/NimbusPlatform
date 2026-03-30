<x-layouts::auth :title="__('Verificação de e-mail')">
    <div class="flex flex-col gap-6">
        <div class="flex flex-col items-center gap-3 text-center">
            <div class="flex h-16 w-16 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-950">
                <svg class="h-8 w-8 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
                </svg>
            </div>

            <div>
                <flux:heading size="xl">{{ __('Verifique seu e-mail') }}</flux:heading>
                <flux:subheading class="mt-1">{{ __('Um link de verificação foi enviado para o seu endereço de e-mail') }}</flux:subheading>
            </div>
        </div>

        <flux:text class="text-center">
            {{ __('Clique no link enviado para o seu e-mail para verificar sua conta e concluir o processo de cadastro.') }}
        </flux:text>

        @if (session('status') == 'verification-link-sent')
            <flux:text class="text-center font-medium !dark:text-green-400 !text-green-600">
                {{ __('Um novo link de verificação foi enviado para o e-mail informado no cadastro.') }}
            </flux:text>
        @endif

        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
            <ul class="space-y-1">
                <li class="flex items-start gap-2">
                    <span class="mt-0.5 shrink-0">•</span>
                    <span>{{ __('Verifique também a pasta de spam ou lixo eletrônico, caso não encontre o e-mail na caixa de entrada.') }}</span>
                </li>
                <li class="flex items-start gap-2">
                    <span class="mt-0.5 shrink-0">•</span>
                    <span>{{ __('O link de verificação expira em 60 minutos. Caso expire, solicite o reenvio abaixo.') }}</span>
                </li>
            </ul>
        </div>

        <div class="flex flex-col items-center justify-between space-y-3">
            <form method="POST" action="{{ route('verification.send') }}" class="w-full">
                @csrf
                <flux:button type="submit" variant="primary" class="w-full">
                    {{ __('Reenviar e-mail de verificação') }}
                </flux:button>
            </form>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <flux:button variant="ghost" type="submit" class="text-sm cursor-pointer" data-test="logout-button">
                    {{ __('Sair') }}
                </flux:button>
            </form>
        </div>
    </div>
</x-layouts::auth>
