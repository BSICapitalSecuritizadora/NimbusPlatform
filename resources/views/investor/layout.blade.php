<!doctype html>
<html lang="pt-br">
<head>
    @include('partials.head', ['title' => $title ?? trim($__env->yieldContent('title', 'Portal do Investidor'))])
</head>
<body class="min-h-screen antialiased">
    @php
        $investorUser = auth('investor')->user();
        $isAuthenticatedInvestor = $investorUser instanceof \App\Models\Investor;
        $portalInitial = $isAuthenticatedInvestor ? strtoupper(mb_substr($investorUser->name, 0, 1)) : 'I';
    @endphp

    <div class="relative min-h-screen overflow-hidden">
        <div class="pointer-events-none absolute inset-x-0 top-0 h-[420px] bg-[radial-gradient(circle_at_top_left,rgba(0,32,91,0.12),transparent_38%),radial-gradient(circle_at_top_right,rgba(212,175,55,0.12),transparent_26%)]"></div>
        <div class="pointer-events-none absolute bottom-0 left-0 h-[320px] w-[320px] rounded-full bg-brand-100/40 blur-3xl"></div>

        <header class="relative z-20">
            <div class="mx-auto max-w-7xl px-4 pt-4 sm:px-6 lg:px-8">
                <div class="bsi-shell-card flex flex-col gap-4 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex items-center justify-between gap-4">
                        <a href="{{ $isAuthenticatedInvestor ? route('investor.dashboard') : route('investor.login') }}" class="flex min-w-0 items-center gap-3 no-underline">
                            <span class="flex size-11 items-center justify-center rounded-[22px] bg-[linear-gradient(135deg,#00205b,#0f2f73)] text-white shadow-[0_18px_36px_rgba(0,32,91,0.22)]">
                                <x-app-logo-icon class="size-5 fill-current text-white" />
                            </span>
                            <span class="min-w-0">
                                <span class="block truncate text-lg font-semibold text-brand-800">BSI Capital</span>
                                <span class="block truncate text-xs font-semibold uppercase tracking-[0.24em] text-gold-500">Portal do Investidor</span>
                            </span>
                        </a>

                        @unless($isAuthenticatedInvestor)
                            <a href="{{ route('site.home') }}" class="hidden items-center gap-2 rounded-full border border-brand-100 bg-brand-50/80 px-4 py-2 text-sm font-semibold text-brand-700 transition hover:border-gold-500/50 hover:bg-white sm:inline-flex">
                                Voltar ao site
                            </a>
                        @endunless
                    </div>

                    @if($isAuthenticatedInvestor)
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-end">
                            <nav class="flex flex-wrap items-center gap-2">
                                <a href="{{ route('investor.dashboard') }}" class="bsi-portal-nav-link {{ request()->routeIs('investor.dashboard') ? 'bsi-portal-nav-link-active' : '' }}">
                                    <flux:icon.home class="size-4" />
                                    <span>Início</span>
                                </a>
                                <a href="{{ route('investor.emissions') }}" class="bsi-portal-nav-link {{ request()->routeIs('investor.emissions') ? 'bsi-portal-nav-link-active' : '' }}">
                                    <flux:icon.chart-bar class="size-4" />
                                    <span>Minhas emissões</span>
                                </a>
                                <a href="{{ route('investor.documents') }}" class="bsi-portal-nav-link {{ request()->routeIs('investor.documents*') ? 'bsi-portal-nav-link-active' : '' }}">
                                    <flux:icon.document-text class="size-4" />
                                    <span>Documentos</span>
                                </a>
                            </nav>

                            <div class="flex items-center gap-3 rounded-full border border-brand-100 bg-brand-50/80 px-3 py-2">
                                <span class="flex size-10 items-center justify-center rounded-full bg-brand-700 text-sm font-bold text-white">
                                    {{ $portalInitial }}
                                </span>
                                <div class="hidden min-w-0 sm:block">
                                    <div class="truncate text-sm font-semibold text-brand-800">{{ $investorUser->name }}</div>
                                    <div class="truncate text-xs text-zinc-500">{{ $investorUser->email }}</div>
                                </div>
                                <form method="POST" action="{{ route('investor.logout') }}">
                                    @csrf
                                    <flux:button variant="subtle" size="sm" type="submit" class="!rounded-full !px-4">
                                        Sair
                                    </flux:button>
                                </form>
                            </div>
                        </div>
                    @else
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="bsi-portal-meta">
                                <flux:icon.shield-check class="size-4" />
                                <span>Acesso seguro</span>
                            </span>
                            <a href="{{ route('site.home') }}" class="inline-flex items-center gap-2 rounded-full border border-brand-100 bg-brand-50/80 px-4 py-2 text-sm font-semibold text-brand-700 transition hover:border-gold-500/50 hover:bg-white sm:hidden">
                                Voltar ao site
                            </a>
                        </div>
                    @endif
                </div>
            </div>
        </header>

        <main class="relative z-10 mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="mb-6 rounded-[24px] border border-emerald-200 bg-emerald-50/90 px-5 py-4 text-sm font-medium text-emerald-700 shadow-sm">
                    {{ session('success') }}
                </div>
            @endif

            @if (session('error'))
                <div class="mb-6 rounded-[24px] border border-red-200 bg-red-50/90 px-5 py-4 text-sm font-medium text-red-700 shadow-sm">
                    {{ session('error') }}
                </div>
            @endif

            @hasSection('content')
                @yield('content')
            @else
                {{ $slot ?? '' }}
            @endif
        </main>
    </div>

    @fluxScripts
</body>
</html>
