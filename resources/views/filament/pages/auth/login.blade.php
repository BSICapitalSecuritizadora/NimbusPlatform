<div class="min-h-screen w-full flex flex-col justify-between items-center bg-[#06151c] text-zinc-800 dark:text-zinc-100 antialiased selection:bg-[#a06e28]/20 selection:text-[#a06e28] relative overflow-x-hidden p-6 sm:p-8 lg:p-12">
    {{-- Efeitos de ambientação sutil de fundo (Navy e Dourado Institucional) --}}
    <div class="pointer-events-none absolute -top-40 left-1/2 -translate-x-1/2 size-[800px] rounded-full bg-[radial-gradient(circle,rgba(34,66,76,0.35)_0%,transparent_70%)] blur-3xl"></div>
    <div class="pointer-events-none absolute -bottom-40 left-1/2 -translate-x-1/2 size-[650px] rounded-full bg-[radial-gradient(circle,rgba(160,110,40,0.12)_0%,transparent_70%)] blur-3xl"></div>

    {{-- Topo: Logotipo BSI Capital acima do card --}}
    <div class="w-full flex justify-center pt-2 sm:pt-4 relative z-10">
        <a href="{{ route('site.home') }}" class="group inline-flex items-center transition" title="BSI Capital">
            <img
                src="{{ asset('images/brand/bsi-capital-logo.png') }}"
                alt="BSI Capital Securitizadora"
                class="h-10 sm:h-12 w-auto object-contain brightness-0 invert opacity-95 group-hover:opacity-100 transition duration-200"
            />
        </a>
    </div>

    {{-- Área Central: Card de Login Corporativo --}}
    <main class="w-full max-w-[460px] my-auto py-6 relative z-10">
        <div class="rounded-3xl border border-zinc-200/90 bg-white p-7 sm:p-9 shadow-[0_25px_60px_rgba(0,0,0,0.35)] dark:border-white/10 dark:bg-[#091b23]/95">
            {{-- Cabeçalho do Card --}}
            <div class="text-center sm:text-left">
                <div class="inline-flex items-center gap-2 text-[0.72rem] font-bold uppercase tracking-[0.2em] text-[#a06e28] dark:text-[#d4af37]">
                    <span class="size-1.5 rounded-full bg-[#a06e28] dark:bg-[#d4af37]"></span>
                    <span>Acesso Administrativo</span>
                </div>

                <h1 class="mt-2.5 text-2xl sm:text-[1.75rem] font-bold tracking-tight text-[#091b23] dark:text-white leading-tight">
                    Entrar no sistema
                </h1>

                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400 leading-relaxed">
                    Utilize sua conta corporativa autorizada para acessar o ambiente administrativo.
                </p>
            </div>

            {{-- Feedback de Erro de Autenticação --}}
            @if(session('loginError'))
                <div
                    class="mt-6 rounded-2xl border border-red-200 bg-red-50/95 p-4 text-sm text-red-800 outline-none focus-visible:ring-2 focus-visible:ring-red-500 dark:border-red-500/30 dark:bg-red-950/50 dark:text-red-300"
                    role="alert"
                    aria-live="assertive"
                    aria-atomic="true"
                    tabindex="-1"
                    autofocus
                >
                    <div class="flex items-start gap-3">
                        <svg class="size-5 shrink-0 text-red-600 dark:text-red-400 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                        <div class="flex-1 font-medium leading-snug">
                            {{ session('loginError') }}
                        </div>
                    </div>
                </div>
            @endif

            {{-- Ação Principal: Botão Microsoft 365 SSO com Feedback de Loading --}}
            <div x-data="{ isRedirecting: false }" class="mt-7">
                <a
                    href="{{ route('auth.azure.redirect') }}"
                    @click="isRedirecting = true"
                    :class="isRedirecting ? 'pointer-events-none opacity-80 cursor-wait' : ''"
                    class="group relative flex w-full items-center justify-between gap-3.5 rounded-2xl border border-zinc-300 bg-white px-5 py-4 text-sm font-semibold text-zinc-900 shadow-sm transition-all duration-200 hover:border-[#a06e28] hover:bg-zinc-50 hover:shadow-md hover:-translate-y-0.5 active:translate-y-0 focus:outline-none focus:ring-2 focus:ring-[#a06e28] focus:ring-offset-2 dark:border-white/15 dark:bg-white/[0.05] dark:text-white dark:hover:border-[#a06e28] dark:hover:bg-white/[0.09] dark:focus:ring-offset-[#091b23]"
                >
                    <div class="flex items-center gap-3.5 min-w-0">
                        {{-- Ícone Oficial Microsoft 365 (ou Spinner durante loading) --}}
                        <span x-show="!isRedirecting" class="shrink-0">
                            <svg class="size-5" viewBox="0 0 23 23" xmlns="http://www.w3.org/2000/svg">
                                <path fill="#f35325" d="M1 1h10v10H1z"/>
                                <path fill="#81bc06" d="M12 1h10v10H12z"/>
                                <path fill="#05a6f0" d="M1 12h10v10H1z"/>
                                <path fill="#ffba08" d="M12 12h10v10H12z"/>
                            </svg>
                        </span>

                        <span x-show="isRedirecting" x-cloak class="shrink-0 animate-spin text-[#a06e28]">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </span>

                        <div class="text-left">
                            <span x-show="!isRedirecting" class="block font-semibold text-zinc-900 dark:text-white leading-tight">
                                Entrar com Microsoft 365
                            </span>
                            <span x-show="isRedirecting" x-cloak class="block font-semibold text-zinc-700 dark:text-zinc-200 leading-tight">
                                Conectando à Microsoft...
                            </span>
                            <span class="block text-[0.75rem] font-normal text-zinc-500 dark:text-zinc-400 mt-0.5">
                                Single Sign-On Corporativo (SSO)
                            </span>
                        </div>
                    </div>

                    {{-- Seta de navegação --}}
                    <svg class="size-5 shrink-0 text-zinc-400 group-hover:text-[#a06e28] group-hover:translate-x-0.5 transition-all" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                    </svg>
                </a>
            </div>

            {{-- Mensagem de Segurança Simplificada --}}
            <div class="mt-6 rounded-2xl border border-zinc-200/80 bg-zinc-50/80 p-3.5 dark:border-white/5 dark:bg-white/[0.02]">
                <div class="flex items-center gap-2.5">
                    <svg class="size-4 shrink-0 text-[#a06e28] dark:text-[#d4af37]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                    <p class="text-xs text-zinc-600 dark:text-zinc-400 leading-snug">
                        Ambiente corporativo de acesso restrito a usuários autorizados.
                    </p>
                </div>
            </div>

            {{-- Suporte Institucional Discreto --}}
            <div class="mt-6 pt-5 border-t border-zinc-100 dark:border-white/5 text-center">
                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                    Problemas com sua conta?
                    <a href="mailto:contato@bsicapital.com.br" class="font-semibold text-[#a06e28] hover:text-[#7f581f] dark:text-[#d4af37] dark:hover:text-white transition underline ml-1">
                        Fale com o suporte
                    </a>
                </p>
            </div>
        </div>
    </main>

    {{-- Rodapé Centralizado Simples --}}
    <footer class="w-full text-center text-xs text-zinc-400/80 dark:text-zinc-500 pt-4 pb-2 relative z-10">
        <p>© {{ date('Y') }} BSI Capital Securitizadora S.A. • Portal Corporativo</p>
    </footer>
</div>
