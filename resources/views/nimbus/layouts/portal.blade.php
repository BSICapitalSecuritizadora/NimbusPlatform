<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>BSI Capital Securitizadora — @yield('title', 'Portal')</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@600&display=swap" rel="stylesheet">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

    <!-- CSS Injection from nimbusdocs-theme.css equivalents or local logic -->
    <style>
        :root {
            --nd-navy-900: #06101c;
            --nd-navy-850: #0f2137;
            --nd-navy-800: #0c1b2e;
            --nd-navy-700: #1b2f48;
            --nd-navy-100: #e6f0fa;
            
            --nd-gold-700: #8a6428;
            --nd-gold-600: #a67f3d;
            --nd-gold-500: #d4a84b;
            --nd-gold-400: #e4c47a;
            --nd-gold-300: #f0dea5;
            --nd-gold-100: #fdf8e6;

            --nd-white: #ffffff;
            --nd-surface-50: #f8fafc;
            --nd-surface-100: #f1f5f9;
            --nd-surface-200: #e2e8f0;

            --nd-success-light: #ecfdf5;
            --nd-success-dark: #059669;

            --nd-gray-400: #9ca3af;
            --nd-gray-500: #6b7280;
            --nd-gray-600: #4b5563;

            --nd-radius: 0.375rem;
            --nd-radius-lg: 0.5rem;
            --nd-radius-xl: 0.75rem;
            --nd-radius-2xl: 1rem;

            --nd-shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --nd-shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --nd-shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);

            --nd-transition: all 0.2s ease;
            --nd-font-heading: 'Plus Jakarta Sans', sans-serif;
        }

        body {
            background-color: var(--nd-surface-50);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        .portal-main {
            flex: 1;
            padding: 3rem 0;
        }

        /* Nav & Footer Utilities */
        .portal-navbar {
            background: linear-gradient(180deg, var(--nd-navy-900) 0%, var(--nd-navy-850) 100%);
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            padding: 0.625rem 0;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }
        .portal-logo-icon {
            width: 40px; height: 40px;
            background: linear-gradient(135deg, var(--nd-gold-400) 0%, var(--nd-gold-600) 100%);
            border-radius: 10px; display: flex; align-items: center; justify-content: center;
            font-size: 1.25rem; color: var(--nd-navy-900);
            box-shadow: 0 0 15px rgba(212, 168, 75, 0.3);
        }
        .portal-nav-link {
            display: flex; align-items: center; gap: 0.5rem;
            color: rgba(255, 255, 255, 0.65) !important; font-weight: 500; font-size: 0.875rem;
            padding: 0.625rem 1.125rem !important; border-radius: 50px; transition: all 0.2s ease;
        }
        .portal-nav-link:hover { color: #ffffff !important; background: rgba(255, 255, 255, 0.08); }
        .portal-nav-link.active {
            color: var(--nd-navy-900) !important; background: linear-gradient(135deg, var(--nd-gold-400) 0%, var(--nd-gold-500) 100%);
            font-weight: 600; box-shadow: 0 2px 12px rgba(212, 168, 75, 0.3);
        }
        .portal-avatar {
            width: 38px; height: 38px; background: linear-gradient(135deg, var(--nd-navy-700) 0%, var(--nd-navy-800) 100%);
            border: 2px solid var(--nd-gold-500); border-radius: 50%; display: flex; align-items: center; justify-content: center;
            color: var(--nd-gold-400); font-weight: 600; font-size: 0.875rem; transition: all 0.2s ease;
        }
        .portal-user-toggle:hover .portal-avatar { box-shadow: 0 0 15px rgba(212, 168, 75, 0.3); transform: scale(1.05); }

        /* General Forms & Buttons */
        .nd-btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.5rem 1.25rem; border-radius: var(--nd-radius-lg); font-weight: 600; font-size: 0.875rem; transition: var(--nd-transition); text-decoration: none; cursor: pointer; border: none; }
        .nd-btn-gold { background: linear-gradient(135deg, var(--nd-gold-500) 0%, var(--nd-gold-600) 100%); color: var(--nd-navy-900); }
        .nd-btn-gold:hover { background: linear-gradient(135deg, var(--nd-gold-400) 0%, var(--nd-gold-500) 100%); transform: translateY(-1px); }
        .nd-btn-outline { background: transparent; border: 1px solid var(--nd-surface-200); color: var(--nd-navy-800); }
        .nd-btn-outline:hover { background: var(--nd-surface-100); border-color: var(--nd-surface-200); }
        .nd-btn-primary { background: var(--nd-navy-800); color: var(--nd-white); }
        .nd-btn-primary:hover { background: var(--nd-navy-700); color: var(--nd-white); }
        .nd-btn-ghost { background: transparent; color: var(--nd-navy-800); }
        .nd-btn-ghost:hover { background: var(--nd-surface-100); }
        
        .nd-card { background: var(--nd-white); border: 1px solid var(--nd-surface-200); border-radius: var(--nd-radius-xl); overflow: hidden; }
        .nd-card-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--nd-surface-200); background: var(--nd-white); }
        
        .nd-badge { display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.35em 0.75em; border-radius: 50px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .nd-badge-warning { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
        .nd-badge-info { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .nd-badge-success { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .nd-badge-danger { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }

        .nd-table-wrapper { width: 100%; overflow-x: auto; }
        .nd-table { width: 100%; border-collapse: separate; border-spacing: 0; }
        .nd-table th { background: var(--nd-surface-50); color: var(--nd-gray-500); font-weight: 600; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; padding: 1rem 1.5rem; border-bottom: 1px solid var(--nd-surface-200); }
        .nd-table td { padding: 1rem 1.5rem; vertical-align: middle; border-bottom: 1px solid var(--nd-surface-100); }
    </style>
    @stack('styles')
</head>
<body>

    @php
        $user = Auth::guard('nimbus')->user();
        $isSubmissions = request()->routeIs('nimbus.submissions.*') || request()->routeIs('nimbus.dashboard');
        $isDocuments = request()->routeIs('nimbus.documents.*');
    @endphp

    <nav class="navbar navbar-expand-lg portal-navbar sticky-top">
        <div class="container-xxl">
            <a class="navbar-brand d-flex align-items-center gap-3" href="{{ route('nimbus.dashboard') }}">
                <div class="portal-logo-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width: 24px;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                </div>
                <div>
                     <div class="portal-logo-title">BSI Capital</div>
                     <div class="portal-logo-subtitle">Portal do Cliente</div>
                </div>
            </a>

            <button class="navbar-toggler border-0 shadow-none p-2" type="button" data-bs-toggle="collapse"
                    data-bs-target="#portalNavbar" aria-controls="portalNavbar">
                <i class="bi bi-list text-white fs-4"></i>
            </button>

            <div class="collapse navbar-collapse" id="portalNavbar">
                <ul class="navbar-nav mx-auto mb-2 mb-lg-0 gap-1 gap-lg-2 align-items-center">
                    <li class="nav-item">
                        <a class="nav-link portal-nav-link {{ request()->routeIs('nimbus.dashboard') ? 'active' : '' }}" href="{{ route('nimbus.dashboard') }}">
                            <i class="bi bi-house-door{{ request()->routeIs('nimbus.dashboard') ? '-fill' : '' }}"></i>
                            <span>Início</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link portal-nav-link {{ $isSubmissions && !request()->routeIs('nimbus.dashboard') ? 'active' : '' }}" href="#">
                            <i class="bi bi-inbox{{ $isSubmissions && !request()->routeIs('nimbus.dashboard') ? '-fill' : '' }}"></i>
                            <span>Meus Envios</span>
                        </a>
                    </li>
                    <li class="nav-item">
                         <a class="nav-link portal-nav-link {{ $isDocuments ? 'active' : '' }}" href="#">
                            <i class="bi bi-folder{{ $isDocuments ? '-fill' : '' }}"></i>
                            <span>Documentos</span>
                        </a>
                    </li>
                </ul>

                <div class="d-flex align-items-center gap-3">
                    <a class="nd-btn nd-btn-gold shadow-sm portal-new-btn" href="#">
                        <i class="bi bi-plus-lg"></i>
                        <span>Novo Envio</span>
                    </a>

                    @if ($user)
                        <div class="dropdown">
                            <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle portal-user-toggle" 
                               data-bs-toggle="dropdown">
                                <div class="portal-avatar">
                                    {{ strtoupper(substr($user->full_name ?? $user->email, 0, 1)) }}
                                </div>
                                <span class="d-none d-lg-block ms-2 text-white-50 small fw-medium">
                                    {{ explode(' ', $user->full_name ?? 'Usuário')[0] }}
                                </span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 mt-3 p-0 rounded-3 overflow-hidden">
                                <li class="p-3 bg-light border-bottom">
                                    <strong class="d-block text-dark text-truncate">{{ $user->full_name }}</strong>
                                    <small class="text-muted d-block text-truncate">{{ $user->email }}</small>
                                </li>
                                <li class="p-2">
                                    <form method="POST" action="{{ route('nimbus.auth.logout') }}">
                                        @csrf
                                        <button type="submit" class="dropdown-item rounded-2 py-2 text-danger d-flex align-items-center gap-2 border-0 bg-transparent w-100">
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
                <div class="alert alert-success shadow-sm rounded-4 border-0 mb-4">
                    <i class="bi bi-check-circle-fill me-2"></i> {{ session('success') }}
                </div>
            @endif
            @if (session('error') || $errors->any())
                <div class="alert alert-danger shadow-sm rounded-4 border-0 mb-4">
                    <i class="bi bi-x-circle-fill me-2"></i> {{ session('error') ?? $errors->first() }}
                </div>
            @endif

            @yield('content')
        </div>
    </main>

    <footer class="mt-auto py-4 bg-white border-top">
        <div class="container-xxl">
            <div class="row align-items-center gy-3">
                <div class="col-md-6 text-center text-md-start">
                    <p class="mb-0 text-secondary small fw-medium">
                        &copy; {{ date('Y') }} BSI Capital Securitizadora S/A. Todos os direitos reservados.
                    </p>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    @stack('scripts')
</body>
</html>
