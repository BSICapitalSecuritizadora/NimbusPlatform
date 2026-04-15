<!doctype html>
<html lang="pt-br">
<head>
    @include('partials.head', ['title' => $title ?? trim($__env->yieldContent('title', 'Portal do Investidor'))])
</head>
<body class="bsi-investor-body min-h-screen antialiased">
    <script>
        document.documentElement.classList.remove('dark');
        document.documentElement.style.colorScheme = 'light';
    </script>

    @php
        $investorUser = auth('investor')->user();
        $isAuthenticatedInvestor = $investorUser instanceof \App\Models\Investor;
        $portalInitial = $isAuthenticatedInvestor ? strtoupper(mb_substr($investorUser->name, 0, 1)) : 'I';
        $showInvestorHeader = $isAuthenticatedInvestor || ! request()->routeIs('investor.login');
    @endphp

    <div class="relative min-h-screen overflow-hidden">
        <div class="pointer-events-none absolute inset-x-0 top-0 h-[420px] bg-[radial-gradient(circle_at_top_left,rgba(0,32,91,0.16),transparent_36%),radial-gradient(circle_at_top_right,rgba(212,175,55,0.18),transparent_24%)]"></div>
        <div class="pointer-events-none absolute left-0 top-[18rem] h-[360px] w-[360px] rounded-full bg-brand-100/55 blur-3xl"></div>
        <div class="pointer-events-none absolute right-0 top-[7rem] h-[280px] w-[280px] rounded-full bg-gold-400/12 blur-3xl"></div>

        @if ($showInvestorHeader)
            <header class="relative z-20">
                <div class="mx-auto max-w-7xl px-4 pt-4 sm:px-6 lg:px-8">
                    <div class="bsi-investor-header-surface flex flex-col gap-4 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
                        <div class="flex items-center justify-between gap-4">
                            <a href="{{ $isAuthenticatedInvestor ? route('investor.dashboard') : route('investor.login') }}" class="flex min-w-0 items-center no-underline">
                                <img src="{{ asset('images/logo-mob.png') }}" alt="BSI Capital" style="max-height: 44px;">
                            </a>
                        </div>

                        @if ($isAuthenticatedInvestor)
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
                        @endif
                    </div>
                </div>
            </header>
        @endif

        <main class="relative z-10 mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 {{ $showInvestorHeader ? '' : 'flex min-h-screen items-center justify-center' }}">
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
