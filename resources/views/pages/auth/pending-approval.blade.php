<x-layouts::auth :title="__('Acesso em análise')">
    <div class="flex flex-col gap-6">
        <div class="flex flex-col items-center gap-3 text-center">
            <div class="flex h-16 w-16 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-950">
                <svg class="h-8 w-8 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
            </div>

            <div>
                <flux:heading size="xl">{{ __('Acesso em análise') }}</flux:heading>
                <flux:subheading class="mt-1">{{ __('Seu cadastro está sendo verificado pela equipe BSI Capital') }}</flux:subheading>
            </div>
        </div>

        <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-5 text-sm leading-relaxed text-zinc-700 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
            <p>{{ __('Obrigado por realizar seu cadastro no portal BSI Capital. Sua conta foi criada com sucesso e está aguardando a aprovação da equipe responsável.') }}</p>
            <p class="mt-3">{{ __('Assim que seu acesso for aprovado, você receberá uma notificação no e-mail cadastrado. Esse processo costuma ser concluído em até um dia útil.') }}</p>
            <p class="mt-3">{{ __('Caso tenha dúvidas, entre em contato com a equipe BSI Capital pelo canal de suporte oficial.') }}</p>
        </div>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <flux:button variant="ghost" type="submit" class="w-full text-sm" data-test="logout-button">
                {{ __('Sair da conta') }}
            </flux:button>
        </form>
    </div>
</x-layouts::auth>
