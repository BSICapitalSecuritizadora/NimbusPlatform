<div class="relative min-h-dvh overflow-x-hidden bg-bsi-navy-950 text-bsi-paper antialiased selection:bg-bsi-gold-500/30 selection:text-bsi-paper">
    <div class="bsi-login-backdrop pointer-events-none absolute inset-0" aria-hidden="true"></div>
    <div class="pointer-events-none absolute inset-x-0 top-0 h-px bg-[linear-gradient(90deg,transparent,rgba(183,131,47,0.5),transparent)]" aria-hidden="true"></div>

    <div class="relative mx-auto flex min-h-dvh w-full max-w-[80rem] flex-col px-5 py-6 sm:px-8 sm:py-8 lg:px-12 lg:py-10">
        <header class="flex justify-center lg:justify-start">
            <a
                href="{{ route('site.home') }}"
                class="inline-flex rounded-sm focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-bsi-gold-500"
                aria-label="Ir para a página inicial da BSI Capital"
            >
                <img
                    src="{{ asset('images/brand/bsi-capital-logo.png') }}"
                    alt="BSI Capital Securitizadora"
                    class="h-10 w-auto object-contain brightness-0 invert sm:h-11 lg:h-12"
                />
            </a>
        </header>

        <main class="flex flex-1 items-center py-10 sm:py-12 lg:py-16">
            <div class="grid w-full items-center gap-14 lg:grid-cols-[minmax(0,1fr)_28.75rem] xl:gap-24">
                <section class="hidden max-w-[34rem] lg:block" aria-label="Segurança do acesso">
                    <div class="h-px w-16 bg-bsi-gold-500" aria-hidden="true"></div>
                    <p class="mt-8 text-3xl font-medium leading-[1.25] tracking-[-0.025em] text-bsi-paper xl:text-[2.5rem]">
                        Ambiente corporativo de acesso restrito a usuários autorizados.
                    </p>

                    <div class="mt-8 flex items-center gap-3 text-sm text-bsi-paper/70">
                        <svg class="size-5 shrink-0 text-bsi-gold-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.75c0 5.592 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.57-.598-3.75h-.152c-3.196 0-6.1-1.25-8.25-3.286Z" />
                        </svg>
                        <span>Autenticação integrada ao Microsoft 365</span>
                    </div>
                </section>

                <section class="rounded-2xl bg-bsi-stone-50 p-6 text-bsi-navy-900 shadow-[0_28px_70px_rgba(0,0,0,0.34)] sm:p-9" aria-labelledby="admin-login-title">
                    <div>
                        <h1 id="admin-login-title" class="text-[1.75rem] font-bold leading-tight tracking-[-0.025em] text-bsi-navy-900 sm:text-[2rem]">
                            Entrar no sistema
                        </h1>

                        <p class="mt-3 max-w-[40ch] text-sm leading-relaxed text-[#535b5e]">
                            Utilize sua conta corporativa autorizada para acessar o ambiente administrativo.
                        </p>
                    </div>

                    @if(session('loginError'))
                        <div
                            class="mt-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 outline-none focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-2 focus-visible:ring-offset-bsi-stone-50"
                            role="alert"
                            aria-live="assertive"
                            aria-atomic="true"
                            tabindex="-1"
                            autofocus
                        >
                            <div class="flex items-start gap-3">
                                <svg class="mt-0.5 size-5 shrink-0 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                                <div class="flex-1 font-medium leading-snug">
                                    {{ session('loginError') }}
                                </div>
                            </div>
                        </div>
                    @endif

                    <div x-data="{ isRedirecting: false }" class="mt-7">
                        <a
                            href="{{ route('auth.azure.redirect') }}"
                            @click="isRedirecting = true"
                            x-bind:aria-busy="isRedirecting"
                            x-bind:aria-disabled="isRedirecting"
                            :class="isRedirecting ? 'pointer-events-none cursor-wait opacity-80' : ''"
                            class="group flex w-full items-center justify-between gap-3 rounded-xl bg-bsi-navy-900 px-4 py-4 text-left text-sm text-bsi-paper shadow-[0_10px_24px_rgba(9,27,35,0.2)] transition-[background-color,box-shadow,opacity] duration-200 ease-out hover:bg-bsi-navy-800 hover:shadow-[0_14px_30px_rgba(9,27,35,0.28)] focus-visible:outline-2 focus-visible:outline-offset-3 focus-visible:outline-bsi-gold-500 motion-reduce:transition-none sm:px-5"
                            aria-label="Entrar com Microsoft 365"
                        >
                            <div class="flex min-w-0 items-center gap-3.5">
                                <span x-show="!isRedirecting" class="shrink-0" aria-hidden="true">
                                    <svg class="size-5" viewBox="0 0 23 23" xmlns="http://www.w3.org/2000/svg">
                                        <path fill="#f35325" d="M1 1h10v10H1z"/>
                                        <path fill="#81bc06" d="M12 1h10v10H12z"/>
                                        <path fill="#05a6f0" d="M1 12h10v10H1z"/>
                                        <path fill="#ffba08" d="M12 12h10v10H12z"/>
                                    </svg>
                                </span>

                                <span x-show="isRedirecting" x-cloak class="shrink-0 animate-spin text-bsi-gold-500 motion-reduce:animate-none" aria-hidden="true">
                                    <svg class="size-5" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                </span>

                                <span class="min-w-0">
                                    <span x-show="!isRedirecting" class="block font-semibold leading-tight">
                                        Entrar com Microsoft 365
                                    </span>
                                    <span x-show="isRedirecting" x-cloak class="block font-semibold leading-tight">
                                        Conectando à Microsoft...
                                    </span>
                                    <span class="mt-1 block text-xs font-normal leading-snug text-bsi-paper/65">
                                        Single Sign-On Corporativo (SSO)
                                    </span>
                                </span>
                            </div>

                            <svg x-show="!isRedirecting" class="size-5 shrink-0 text-bsi-gold-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7" />
                            </svg>
                        </a>
                    </div>

                    <div class="mt-5 flex items-start gap-3 border-t border-bsi-stone-100 pt-5 text-xs leading-relaxed text-[#596164] lg:hidden">
                        <svg class="mt-0.5 size-4 shrink-0 text-bsi-gold-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2Zm10-10V7a4 4 0 0 0-8 0v4h8Z" />
                        </svg>
                        <p>Ambiente corporativo de acesso restrito a usuários autorizados.</p>
                    </div>

                    <div class="mt-6 border-t border-bsi-stone-100 pt-5 text-center">
                        <p class="text-xs text-[#60676a]">
                            Problemas com sua conta?
                            <a href="mailto:contato@bsicapital.com.br" class="ml-1 font-semibold text-bsi-gold-600 underline decoration-bsi-gold-500/50 underline-offset-3 transition-colors duration-200 hover:text-bsi-navy-900 focus-visible:rounded-sm focus-visible:outline-2 focus-visible:outline-offset-3 focus-visible:outline-bsi-gold-500">
                                Fale com o suporte
                            </a>
                        </p>
                    </div>
                </section>
            </div>
        </main>

        <footer class="flex flex-col items-center justify-between gap-1 text-center text-xs text-bsi-paper/55 sm:flex-row sm:text-left">
            <p>© {{ date('Y') }} BSI Capital Securitizadora S.A.</p>
            <p>Portal Corporativo</p>
        </footer>
    </div>
</div>
