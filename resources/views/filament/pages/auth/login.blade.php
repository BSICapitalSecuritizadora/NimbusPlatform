<div class="relative min-h-dvh overflow-x-hidden bg-bsi-navy-950 text-bsi-paper antialiased selection:bg-bsi-gold-500/30 selection:text-bsi-paper">
    <div class="bsi-login-backdrop pointer-events-none absolute inset-0" aria-hidden="true"></div>
    <div class="pointer-events-none absolute inset-x-0 top-0 h-px bg-[linear-gradient(90deg,transparent,rgba(183,131,47,0.45),transparent)]" aria-hidden="true"></div>

    <div class="relative mx-auto flex min-h-dvh w-full max-w-[78rem] flex-col justify-between px-5 py-6 sm:px-8 sm:py-8 lg:px-12 lg:py-10">
        {{-- Top Branding Header --}}
        <header class="flex items-center justify-between">
            <a
                href="{{ route('site.home') }}"
                class="inline-flex items-center rounded-sm transition-opacity duration-150 hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-bsi-gold-500"
                aria-label="Ir para a página inicial da BSI Capital"
            >
                <img
                    src="{{ asset('images/brand/bsi-capital-logo.png') }}"
                    alt="BSI Capital Securitizadora"
                    class="h-9 w-auto object-contain sm:h-10 lg:h-11"
                />
            </a>

            <div class="hidden items-center gap-2 rounded-full border border-white/[0.08] bg-bsi-navy-900/70 px-3 py-1 text-[11px] font-medium tracking-wide text-[#8b9ca7] sm:inline-flex">
                <span class="size-1.5 rounded-full bg-emerald-500"></span>
                <span>Ambiente Seguro</span>
            </div>
        </header>

        {{-- Main Two-Column Composition --}}
        <main class="flex flex-1 items-center py-8 sm:py-10 lg:py-12">
            <div class="grid w-full items-center gap-10 lg:grid-cols-[minmax(0,1.15fr)_27.5rem] lg:gap-14 xl:grid-cols-[minmax(0,1.2fr)_29rem] xl:gap-20">
                
                {{-- Left: Institutional & Security Context --}}
                <section class="max-w-[36rem]" aria-labelledby="institutional-context-heading">
                    <div class="inline-flex items-center gap-2 rounded-md border border-bsi-gold-500/30 bg-bsi-gold-500/10 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-bsi-gold-500">
                        <span class="size-1.5 rounded-full bg-bsi-gold-500"></span>
                        PORTAL ADMINISTRATIVO
                    </div>

                    <h1 id="institutional-context-heading" class="mt-4 text-2xl font-semibold leading-[1.22] tracking-[-0.025em] text-bsi-paper sm:text-3xl lg:text-[2.25rem]">
                        Acesso corporativo seguro e controlado.
                    </h1>

                    <p class="mt-3.5 text-sm leading-relaxed text-[#8b9ca7] sm:text-base">
                        Este ambiente é destinado exclusivamente a usuários autorizados para atividades administrativas e operacionais da BSI Capital Securitizadora.
                    </p>

                    {{-- Security & Governance Box --}}
                    <div class="mt-7 rounded-xl border border-white/[0.08] bg-bsi-navy-900/70 p-4.5 backdrop-blur-sm sm:p-5">
                        <div class="flex items-start gap-3.5">
                            <div class="flex size-9 shrink-0 items-center justify-center rounded-lg border border-bsi-gold-500/25 bg-bsi-navy-800 text-bsi-gold-500 shadow-sm" aria-hidden="true">
                                <svg class="size-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.75c0 5.592 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.57-.598-3.75h-.152c-3.196 0-6.1-1.25-8.25-3.286Z" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-xs font-semibold uppercase tracking-wider text-bsi-paper/90">
                                    Governança &amp; Conformidade
                                </h2>
                                <p class="mt-1 text-xs leading-relaxed text-[#8b9ca7]">
                                    Autenticação integrada ao Microsoft 365 com Single Sign-On (SSO) para garantir segurança, governança e conformidade.
                                </p>
                            </div>
                        </div>
                    </div>

                    {{-- Compact Institutional Indicators --}}
                    <div class="mt-6 hidden flex-wrap items-center gap-2 sm:flex">
                        <div class="inline-flex items-center gap-2 rounded-lg border border-white/[0.06] bg-bsi-navy-900/50 px-3 py-1.5 text-xs text-[#8b9ca7]">
                            <svg class="size-3.5 text-bsi-gold-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" />
                            </svg>
                            <span>Acesso corporativo</span>
                        </div>
                        <div class="inline-flex items-center gap-2 rounded-lg border border-white/[0.06] bg-bsi-navy-900/50 px-3 py-1.5 text-xs text-[#8b9ca7]">
                            <svg class="size-3.5 text-bsi-gold-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                            </svg>
                            <span>Autenticação segura</span>
                        </div>
                        <div class="inline-flex items-center gap-2 rounded-lg border border-white/[0.06] bg-bsi-navy-900/50 px-3 py-1.5 text-xs text-[#8b9ca7]">
                            <svg class="size-3.5 text-bsi-gold-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 0 1-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0 1 15 18.257V17.25m6-12V15a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 15V5.25m18 0A2.25 2.25 0 0 0 18.75 3H5.25A2.25 2.25 0 0 0 3 5.25m18 0H3" />
                            </svg>
                            <span>Ambiente administrativo</span>
                        </div>
                    </div>
                </section>

                {{-- Right: Authentication Card --}}
                <section class="relative rounded-2xl border border-white/[0.1] bg-gradient-to-b from-[#0c242e] to-bsi-navy-900 p-6 shadow-[0_20px_50px_rgba(0,0,0,0.5)] backdrop-blur-md sm:p-8" aria-labelledby="admin-login-title">
                    <div class="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-bsi-gold-500/40 to-transparent" aria-hidden="true"></div>

                    <div>
                        <h2 id="admin-login-title" class="text-xl font-bold tracking-tight text-bsi-paper sm:text-2xl">
                            Entrar no sistema
                        </h2>

                        <p class="mt-2 text-xs leading-relaxed text-[#8b9ca7] sm:text-sm">
                            Utilize sua conta corporativa autorizada para acessar o ambiente administrativo.
                        </p>
                    </div>

                    @if(session('loginError'))
                        <div
                            class="mt-5 rounded-xl border border-red-500/30 bg-red-950/40 p-3.5 text-xs sm:text-sm text-red-200 outline-none focus-visible:ring-2 focus-visible:ring-red-500"
                            role="alert"
                            aria-live="assertive"
                            aria-atomic="true"
                            tabindex="-1"
                            autofocus
                        >
                            <div class="flex items-start gap-2.5">
                                <svg class="mt-0.5 size-4 shrink-0 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                                <div class="flex-1 font-medium leading-snug">
                                    {{ session('loginError') }}
                                </div>
                            </div>
                        </div>
                    @endif

                    <div x-data="{ isRedirecting: false }" class="mt-6">
                        <a
                            href="{{ route('auth.azure.redirect') }}"
                            @click="isRedirecting = true"
                            x-bind:aria-busy="isRedirecting"
                            x-bind:aria-disabled="isRedirecting"
                            :class="isRedirecting ? 'pointer-events-none cursor-wait opacity-75' : ''"
                            class="group relative flex w-full items-center justify-between gap-3 rounded-xl border border-white/12 bg-bsi-navy-800 px-4 py-3.5 text-left shadow-[0_8px_20px_rgba(0,0,0,0.3)] transition-all duration-200 ease-out hover:border-bsi-gold-500/50 hover:bg-[#153945] hover:shadow-[0_12px_28px_rgba(0,0,0,0.4)] focus-visible:outline-2 focus-visible:outline-offset-3 focus-visible:outline-bsi-gold-500 motion-reduce:transition-none sm:px-5 sm:py-4"
                            aria-label="Entrar com Microsoft 365"
                        >
                            <div class="flex min-w-0 items-center gap-3.5">
                                <span x-show="!isRedirecting" class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-black/25 p-2" aria-hidden="true">
                                    <svg class="size-5" viewBox="0 0 23 23" xmlns="http://www.w3.org/2000/svg">
                                        <path fill="#f35325" d="M1 1h10v10H1z"/>
                                        <path fill="#81bc06" d="M12 1h10v10H12z"/>
                                        <path fill="#05a6f0" d="M1 12h10v10H1z"/>
                                        <path fill="#ffba08" d="M12 12h10v10H12z"/>
                                    </svg>
                                </span>

                                <span x-show="isRedirecting" x-cloak class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-black/25 p-2 animate-spin text-bsi-gold-500 motion-reduce:animate-none" aria-hidden="true">
                                    <svg class="size-5" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                </span>

                                <span class="min-w-0">
                                    <span x-show="!isRedirecting" class="block text-sm font-semibold leading-tight text-bsi-paper sm:text-base">
                                        Entrar com Microsoft 365
                                    </span>
                                    <span x-show="isRedirecting" x-cloak class="block text-sm font-semibold leading-tight text-bsi-paper sm:text-base">
                                        Conectando à Microsoft...
                                    </span>
                                    <span class="mt-0.5 block text-xs font-normal leading-snug text-[#8b9ca7]">
                                        Single Sign-On Corporativo (SSO)
                                    </span>
                                </span>
                            </div>

                            <div x-show="!isRedirecting" class="flex size-7 shrink-0 items-center justify-center rounded-full bg-white/[0.04] text-bsi-gold-500 transition-transform duration-200 group-hover:translate-x-0.5 group-hover:bg-bsi-gold-500/10" aria-hidden="true">
                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.25">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7" />
                                </svg>
                            </div>
                        </a>
                    </div>

                    <div class="mt-5 flex items-center justify-center gap-2 rounded-lg border border-white/[0.06] bg-black/20 px-3.5 py-2.5 text-center text-xs leading-relaxed text-[#7e8f9b]">
                        <svg class="size-3.5 shrink-0 text-bsi-gold-500/80" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                        </svg>
                        <span>Acesso restrito a usuários autorizados. Uso interno.</span>
                    </div>

                    <div class="mt-6 border-t border-white/[0.08] pt-4 text-center">
                        <p class="text-xs text-[#7e8f9b]">
                            Problemas com sua conta?
                            <a
                                href="mailto:contato@bsicapital.com.br"
                                class="ml-1 font-medium text-bsi-gold-500 underline decoration-bsi-gold-500/40 underline-offset-3 transition-colors duration-150 hover:text-bsi-gold-400 hover:decoration-bsi-gold-400 focus-visible:rounded-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-bsi-gold-500"
                            >
                                Fale com o suporte
                            </a>
                        </p>
                    </div>
                </section>
            </div>
        </main>

        {{-- Footer --}}
        <footer class="flex flex-col items-center justify-between gap-2 border-t border-white/[0.06] pt-5 text-center text-xs text-[#627582] sm:flex-row sm:text-left">
            <p>© {{ date('Y') }} BSI Capital Securitizadora S.A.</p>
            <p>Portal Corporativo &bull; Uso Interno</p>
        </footer>
    </div>
</div>
