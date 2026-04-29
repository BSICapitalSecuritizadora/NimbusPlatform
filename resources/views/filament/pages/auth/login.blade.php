<x-filament-panels::page.simple>
    <div class="grid gap-4">
        @if(session('loginError'))
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-400">
                {{ session('loginError') }}
            </div>
        @endif

        <a href="{{ route('auth.azure.redirect') }}"
           class="flex w-full items-center justify-center gap-3 rounded-xl border border-zinc-200 !bg-white px-5 py-3 text-sm font-semibold shadow-sm transition-all hover:border-gold-500/50 hover:bg-zinc-50 dark:border-white/10 dark:!bg-zinc-900/50 dark:hover:bg-zinc-800">
            <svg class="size-5" viewBox="0 0 23 23" xmlns="http://www.w3.org/2000/svg">
                <path fill="#f3f3f3" d="M0 0h23v23H0z"/>
                <path fill="#f35325" d="M1 1h10v10H1z"/>
                <path fill="#81bc06" d="M12 1h10v10H12z"/>
                <path fill="#05a6f0" d="M1 12h10v10H1z"/>
                <path fill="#ffba08" d="M12 12h10v10H12z"/>
            </svg>
            <span class="!text-zinc-800 dark:!text-white">Entrar com Microsoft 365</span>
        </a>
        <p class="text-center text-sm text-zinc-500 dark:text-zinc-400">
            Acesso exclusivo com a conta corporativa Microsoft 365.
        </p>
    </div>
</x-filament-panels::page.simple>
