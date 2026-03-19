<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'BSI Capital')</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        :root{
            --brand: #00205b;        /* azul marinho */
            --gold: #ffc20e;         /* dourado (accent) */

            /* Light (Opea-like) */
            --bg: #f7f8fb;
            --surface: #ffffff;
            --text: #0b1220;
            --muted: #5b667a;
            --border: #e7ebf3;

            --nav-bg: rgba(255,255,255,.90);
            --hero-1: rgba(0,32,91,.14);
            --hero-2: rgba(0,32,91,.06);
        }

        /* Auto dark when OS/browser is dark */
        @media (prefers-color-scheme: dark) {
            :root{
                /* Dark (preto + branco) */
                --bg: #070a0f;
                --surface: #0b0f17;
                --text: #f1f5f9;
                --muted: #9aa4b2;
                --border: rgba(255,255,255,.10);

                --nav-bg: rgba(11,15,23,.75);
                --hero-1: rgba(255,255,255,.06);
                --hero-2: rgba(255,194,14,.10);
            }
        }

        /* Manual override (optional) */
        html[data-theme="light"]{
            --bg: #f7f8fb;
            --surface: #ffffff;
            --text: #0b1220;
            --muted: #5b667a;
            --border: #e7ebf3;
            --nav-bg: rgba(255,255,255,.90);
            --hero-1: rgba(0,32,91,.14);
            --hero-2: rgba(0,32,91,.06);
        }
        html[data-theme="dark"]{
            --bg: #070a0f;
            --surface: #0b0f17;
            --text: #f1f5f9;
            --muted: #9aa4b2;
            --border: rgba(255,255,255,.10);
            --nav-bg: rgba(11,15,23,.75);
            --hero-1: rgba(255,255,255,.06);
            --hero-2: rgba(255,194,14,.10);
        }

        body{
            font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        /* Navbar */
        .navbar{
            background: var(--nav-bg);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border);
        }

        .navbar-brand{
            color: var(--brand) !important;
            letter-spacing: -0.02em;
        }

        .nav-link{
            color: color-mix(in oklab, var(--text) 86%, transparent) !important;
            font-weight: 500;
        }
        .nav-link:hover{
            color: var(--brand) !important;
        }

        /* Cards / surfaces */
        .card{
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
        }

        .text-muted{ color: var(--muted) !important; }

        /* Buttons */
        .btn-brand{
            background: var(--brand);
            border-color: var(--brand);
            color:#fff;
        }
        .btn-brand:hover{ opacity:.95; color:#fff; }

        .btn-outline-brand{
            border-color: color-mix(in oklab, var(--brand) 70%, var(--border) 30%);
            color: var(--brand);
            background: transparent;
        }
        .btn-outline-brand:hover{
            border-color: var(--gold);
            color: var(--text);
            background: color-mix(in oklab, var(--gold) 18%, transparent);
        }

        /* Gold only in badges/hover */
        .badge-soft{
            background: color-mix(in oklab, var(--gold) 14%, transparent);
            border: 1px solid color-mix(in oklab, var(--gold) 24%, var(--border) 76%);
            color: color-mix(in oklab, var(--text) 80%, var(--brand) 20%);
            font-weight: 600;
        }

        /* Hero */
        .hero{
            background:
                radial-gradient(1200px 600px at 10% 10%, var(--hero-1), transparent 55%),
                radial-gradient(900px 500px at 90% 20%, var(--hero-2), transparent 60%),
                var(--surface);
            border-bottom: 1px solid var(--border);
        }

        .kicker{
            color: var(--muted);
            font-weight: 600;
            letter-spacing: .06em;
            text-transform: uppercase;
            font-size: .80rem;
        }

        /* Mega menu */
        .dropdown-mega{ position: static; }

        .mega-menu{
            width: min(1100px, 96vw);
            border: 1px solid var(--border);
            background: var(--surface);
            border-radius: 18px;
            padding: 18px;
            box-shadow: 0 20px 60px rgba(0,0,0,.12);
        }
        @media (prefers-color-scheme: dark){
            .mega-menu{ box-shadow: 0 24px 70px rgba(0,0,0,.55); }
        }

        .mega-title{
            font-weight: 700;
            letter-spacing: -0.01em;
            margin-bottom: 6px;
        }

        .mega-kicker{
            font-size:.82rem;
            color: var(--muted);
            margin-bottom: 10px;
        }

        .mega-link{
            display:block;
            padding: 8px 10px;
            border-radius: 10px;
            color: var(--text);
            text-decoration: none;
        }
        .mega-link:hover{
            background: color-mix(in oklab, var(--brand) 8%, transparent);
            color: var(--text);
        }

        /* Footer */
        .footer{
            border-top: 1px solid var(--border);
            color: var(--muted);
        }

        /* Theme selector (optional UI) */
        .theme-select{
            min-width: 140px;
        }
    </style>

    @stack('head')
</head>
<body>
@php
    // Local: http://localhost:8000/portal
    // Prod: https://portal.bsicapital.com.br
    $portalUrl = env('APP_PORTAL_URL', '/portal');
@endphp

<nav class="navbar navbar-expand-lg sticky-top">
    <div class="container py-2">
        <a class="navbar-brand fw-bold" href="{{ route('site.home') }}">BSI Capital</a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="nav">
            <ul class="navbar-nav ms-auto gap-lg-2 align-items-lg-center">

                {{-- Indústrias --}}
                <li class="nav-item dropdown dropdown-mega">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        Indústrias
                    </a>
                    <div class="dropdown-menu mega-menu p-0 border-0">
                        <div class="row g-3">
                            <div class="col-lg-4 p-3">
                                <div class="mega-title">Imobiliário</div>
                                <div class="mega-kicker">Operações lastreadas e estruturação completa.</div>
                                <a class="mega-link" href="#">CRI / Real Estate</a>
                                <a class="mega-link" href="#">Loteamentos</a>
                                <a class="mega-link" href="#">Incorporação</a>
                            </div>

                            <div class="col-lg-4 p-3">
                                <div class="mega-title">Agronegócio</div>
                                <div class="mega-kicker">Crédito estruturado para cadeias e projetos.</div>
                                <a class="mega-link" href="#">CRA</a>
                                <a class="mega-link" href="#">Cooperativas</a>
                                <a class="mega-link" href="#">Projetos</a>
                            </div>

                            <div class="col-lg-4 p-3">
                                <div class="mega-title">Infra & Empresas</div>
                                <div class="mega-kicker">Estruturas para expansão e investimentos.</div>
                                <a class="mega-link" href="#">CR (futuro)</a>
                                <a class="mega-link" href="#">Recebíveis</a>
                                <a class="mega-link" href="#">Estruturação sob medida</a>
                            </div>
                        </div>
                    </div>
                </li>

                {{-- Serviços --}}
                <li class="nav-item dropdown dropdown-mega">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        Serviços
                    </a>
                    <div class="dropdown-menu mega-menu p-0 border-0">
                        <div class="row g-3">
                            <div class="col-lg-4 p-3">
                                <div class="mega-title">Estruturação</div>
                                <div class="mega-kicker">Modelagem, documentação e governança.</div>
                                <a class="mega-link" href="#">Originação</a>
                                <a class="mega-link" href="#">Estrutura jurídica</a>
                                <a class="mega-link" href="#">Registro e distribuição</a>
                            </div>

                            <div class="col-lg-4 p-3">
                                <div class="mega-title">Gestão</div>
                                <div class="mega-kicker">Transparência e acompanhamento ao investidor.</div>
                                <a class="mega-link" href="{{ $portalUrl }}">Portal do investidor</a>
                                <a class="mega-link" href="#">Relatórios</a>
                                <a class="mega-link" href="#">Compliance</a>
                            </div>

                            <div class="col-lg-4 p-3">
                                <div class="mega-title">Tecnologia</div>
                                <div class="mega-kicker">Automação e trilha de auditoria.</div>
                                <a class="mega-link" href="#">Documentos com ACL</a>
                                <a class="mega-link" href="#">Auditoria de acessos</a>
                                <a class="mega-link" href="#">Integrações</a>
                            </div>
                        </div>
                    </div>
                </li>

                {{-- Institucional --}}
                <li class="nav-item dropdown dropdown-mega">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        Institucional
                    </a>
                    <div class="dropdown-menu mega-menu p-0 border-0">
                        <div class="row g-3">
                            <div class="col-lg-4 p-3">
                                <div class="mega-title">A BSI</div>
                                <div class="mega-kicker">História, time e visão.</div>
                                <a class="mega-link" href="#">Sobre</a>
                                <a class="mega-link" href="#">Governança</a>
                                <a class="mega-link" href="#">Compliance</a>
                            </div>

                            <div class="col-lg-4 p-3">
                                <div class="mega-title">Relações com Investidores</div>
                                <div class="mega-kicker">Documentos públicos e comunicados.</div>
                                <a class="mega-link" href="#">R.I</a>
                                <a class="mega-link" href="#">Fatos relevantes</a>
                                <a class="mega-link" href="#">Assembleias</a>
                            </div>

                            <div class="col-lg-4 p-3">
                                <div class="mega-title">Contato</div>
                                <div class="mega-kicker">Fale com a BSI.</div>
                                <a class="mega-link" href="#">Fale conosco</a>
                                <a class="mega-link" href="#">Trabalhe conosco</a>
                            </div>
                        </div>
                    </div>
                </li>
            </ul>

            <div class="d-flex ms-lg-3 gap-2 align-items-center mt-3 mt-lg-0">
                {{-- (Opcional) Seletor de tema Auto/Claro/Escuro --}}
                <select class="form-select form-select-sm theme-select" onchange="BSITheme.set(this.value)">
                    <option value="auto">Tema: Auto</option>
                    <option value="light">Tema: Claro</option>
                    <option value="dark">Tema: Escuro</option>
                </select>

                <a href="{{ $portalUrl }}" class="btn btn-outline-brand btn-sm">Portal do Investidor</a>
                <a href="#" class="btn btn-brand btn-sm">Falar com a BSI</a>
            </div>
        </div>
    </div>
</nav>

@yield('content')

<footer class="footer py-4 mt-5">
    <div class="container d-flex flex-column flex-md-row justify-content-between gap-2">
        <div>© {{ date('Y') }} BSI Capital. Todos os direitos reservados.</div>
        <div class="small">Ambiente local • Versão MVP</div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Theme system: auto (prefers-color-scheme), light, dark
    (function () {
        const key = 'bsi_theme'; // 'auto' | 'light' | 'dark'
        const saved = localStorage.getItem(key) || 'auto';

        function apply(mode){
            if(mode === 'auto'){
                document.documentElement.removeAttribute('data-theme');
            } else {
                document.documentElement.setAttribute('data-theme', mode);
            }
        }
        apply(saved);

        window.BSITheme = {
            set(mode){
                localStorage.setItem(key, mode);
                apply(mode);
            },
            get(){ return localStorage.getItem(key) || 'auto'; }
        };

        // Set select value on load
        const sel = document.querySelector('.theme-select');
        if (sel) sel.value = saved;
    })();
</script>

@stack('scripts')
</body>
</html>