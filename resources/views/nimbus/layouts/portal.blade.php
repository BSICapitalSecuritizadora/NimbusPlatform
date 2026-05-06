<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>BSI Capital Securitizadora — @yield('title', 'Portal')</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;500;600&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        body {
            background-color: #F5F7FB;
            background-image: radial-gradient(1200px 400px at 50% -100px, rgba(11,27,54,.04), transparent 60%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        .container-xxl { max-width: 1400px; }

        .portal-main {
            flex: 1;
            padding: 3rem 0;
        }

        .wizard-step { display: none; }
        .wizard-step.active { display: block; }

        .transition-fast { transition: all 0.2s ease; }
    </style>
    @stack('styles')
</head>
<body>

    @php
        $user = Auth::guard('nimbus')->user();
        $isSubmissions = request()->routeIs('nimbus.submissions.*') || request()->routeIs('nimbus.dashboard');
        $isDocuments = request()->routeIs('nimbus.documents.*');
    @endphp

    <nav class="p-header navbar navbar-expand-lg sticky-top p-0 border-0 shadow-none bg-navy-900">
        <div class="container-xxl p-0 relative">
            <div class="flex items-center w-full px-4 h-[72px]">
                <!-- Brand Zone -->
                <div class="flex items-center h-full pr-6 border-r border-white/5">
                    <a class="flex items-center no-underline" href="{{ route('nimbus.dashboard') }}">
                        <img
                            src="{{ asset('images/bsi-capital-logo.png') }}"
                            alt="BSI Capital Securitizadora"
                            class="block h-[34px] w-auto max-w-[220px]"
                        >
                    </a>
                </div>

                <!-- Navigation Zone -->
                <div class="p-nav flex-1 flex items-center h-full px-6">
                    <ul class="flex items-center gap-2 m-0 p-0 list-none h-full">
                        <li class="h-full">
                            <a class="flex items-center gap-2 px-4 h-full text-[13.5px] font-medium transition-all no-underline {{ request()->routeIs('nimbus.dashboard') ? 'text-white border-b-2 border-gold-500' : 'text-white/60 hover:text-white' }}" href="{{ route('nimbus.dashboard') }}">
                                <i class="bi bi-house text-[14px]"></i>
                                <span>Início</span>
                            </a>
                        </li>
                        <li class="h-full">
                            <a class="flex items-center gap-2 px-4 h-full text-[13.5px] font-medium transition-all no-underline {{ $isSubmissions && !request()->routeIs('nimbus.dashboard') ? 'text-white border-b-2 border-gold-500' : 'text-white/60 hover:text-white' }}" href="{{ route('nimbus.submissions.index') }}">
                                <i class="bi bi-inbox text-[14px]"></i>
                                <span>Meus Envios</span>
                            </a>
                        </li>
                        <li class="h-full">
                             <a class="flex items-center gap-2 px-4 h-full text-[13.5px] font-medium transition-all no-underline {{ $isDocuments ? 'text-white border-b-2 border-gold-500' : 'text-white/60 hover:text-white' }}" href="{{ route('nimbus.documents.index') }}">
                                <i class="bi bi-folder text-[14px]"></i>
                                <span>Documentos</span>
                            </a>
                        </li>
                    </ul>
                </div>

                <!-- Action Zone -->
                <div class="flex items-center gap-6 h-full pl-6 border-l border-white/5">
                    <a class="p-btn-primary flex items-center justify-center h-[38px] px-[18px] bg-gold-500 border border-gold-600 rounded-[4px] text-white text-[13.5px] font-semibold no-underline shadow-[inset_0_1px_0_rgba(255,255,255,0.18)] transition-all hover:bg-gold-400 hover:text-white" href="{{ route('nimbus.submissions.create') }}">
                        <span>Nova solicitação</span>
                    </a>

                    @if ($user)
                        <div class="p-user dropdown h-full">
                            <button type="button" class="flex items-center h-full gap-3 text-white no-underline border-0 p-0 shadow-none bg-transparent"
                               data-bs-toggle="dropdown" aria-expanded="false">
                                <div class="avatar w-8 h-8 rounded-full bg-gradient-to-b from-[#1F3F75] to-[#0B1B36] border border-[rgba(184,150,74,0.6)] flex items-center justify-center text-[#C9A66A] text-xs font-semibold">
                                    {{ strtoupper(substr($user->full_name ?? 'U', 0, 1)) }}{{ strtoupper(substr(explode(' ', $user->full_name ?? 'U')[1] ?? '', 0, 1)) }}
                                </div>
                                <div class="flex flex-col items-start leading-tight d-none d-lg-flex">
                                    <span class="text-white text-[13px] font-medium">{{ $user->full_name ?? $user->email }}</span>
                                </div>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 mt-2 p-0 rounded-[8px] overflow-hidden">
                                <li class="p-3 bg-ink-50 border-b border-ink-100">
                                    <strong class="d-block text-ink-900 text-[13px] text-truncate">{{ $user->full_name }}</strong>
                                    <small class="text-ink-500 d-block text-[11px] text-truncate">{{ $user->email }}</small>
                                </li>
                                <li class="p-1">
                                    <form method="POST" action="{{ route('nimbus.auth.logout') }}">
                                        @csrf
                                        <button type="submit" class="dropdown-item rounded-[4px] py-2 text-rose-600 text-[13px] font-medium d-flex align-items-center gap-2 border-0 bg-transparent w-100">
                                            <i class="bi bi-box-arrow-right"></i> Sair
                                        </button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </nav>

    <main class="portal-main">
        <div class="container-xxl">
            @if (session('success'))
                <div class="alert alert-success shadow-sm rounded-[4px] border border-emerald-600/18 bg-emerald-50 text-emerald-600 font-medium mb-4">
                    <i class="bi bi-check-circle-fill me-2"></i> {{ session('success') }}
                </div>
            @endif

            @if (session('error') || $errors->any())
                <div class="alert alert-danger shadow-sm rounded-[4px] border border-rose-600/18 bg-rose-50 text-rose-600 font-medium mb-4">
                    <i class="bi bi-x-circle-fill me-2"></i> {{ session('error') ?? $errors->first() }}
                </div>
            @endif

            @yield('content')
        </div>
    </main>

    <footer class="mt-auto py-6 bg-white border-t border-ink-200">
        <div class="container-xxl">
            <div class="text-center">
                <p class="mb-0 text-ink-400 font-inter text-[12.5px] font-medium">
                    &copy; {{ date('Y') }} BSI Capital Securitizadora S/A. Todos os direitos reservados.
                </p>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    @stack('scripts')
</body>
</html>
