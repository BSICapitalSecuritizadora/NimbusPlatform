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
            --gold: #d4af37;         /* dourado mais elegante */

            /* Light (Opea-like) */
            --bg: #f7f8fb;
            --surface: #ffffff;
            --text: #0b1220;
            --muted: #5b667a;
            --border: #e7ebf3;

            --brand-outline: var(--brand);
            --brand-outline-border: color-mix(in oklab, var(--brand) 70%, var(--border) 30%);

            --nav-bg: rgba(255,255,255,.90);
            --hero-1: rgba(0,32,91,.14);
            --hero-2: rgba(0,32,91,.06);

            --brand-dark: #4e2a4e; /* Berinjela Opea */
            --brand-light: #f9f7f9; /* Background Opea */
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

                --brand-outline: #90c2ff;
                --brand-outline-border: rgba(144, 194, 255, 0.3);

                --nav-bg: rgba(11,15,23,.75);
                --hero-1: rgba(255,255,255,.06);
                --hero-2: rgba(212,175,55,.10);
            }
        }

        /* Manual override (optional) */
        html[data-theme="light"]{
            --bg: #f7f8fb;
            --surface: #ffffff;
            --text: #0b1220;
            --muted: #5b667a;
            --border: #e7ebf3;
            --brand-outline: var(--brand);
            --brand-outline-border: color-mix(in oklab, var(--brand) 70%, var(--border) 30%);
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
            --brand-outline: #90c2ff;
            --brand-outline-border: rgba(144, 194, 255, 0.3);
            --nav-bg: rgba(11,15,23,.75);
            --hero-1: rgba(255,255,255,.06);
            --hero-2: rgba(212,175,55,.10);
        }

        body{
            font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Navbar */
        .navbar{
            background: var(--nav-bg);
            backdrop-filter: blur(12px);
            border: 1px solid var(--border);
            box-shadow: 0 10px 40px rgba(0,0,0,.08);
            border-radius: 50rem;
            max-width: 1200px;
            margin: 1.5rem auto;
            top: 1.5rem;
            padding-left: 1rem;
            padding-right: 1rem;
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
            border-radius: 32px;
            color: var(--text);
        }
        
        .list-group-item {
            color: var(--text);
        }

        .card-hover:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.1) !important;
        }

        .emission-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 20px 40px rgba(0,32,91,0.12) !important;
            border-color: color-mix(in oklab, var(--gold) 30%, var(--border) 70%);
        }

        .ri-item:hover {
            background: rgba(0,32,91,0.02) !important;
            transform: translateX(4px);
        }

        .text-muted{ color: var(--muted) !important; }

        /* Buttons */
        .btn{
            border-radius: 50rem;
            padding: 0.5rem 1.25rem;
            font-weight: 500;
        }

        .btn-brand{
            background: var(--brand);
            border-color: var(--brand);
            color:#fff;
        }
        .btn-brand:hover{ opacity:.95; color:#fff; }

        .btn-outline-brand{
            border-color: var(--brand-outline-border);
            color: var(--brand-outline);
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

        /* Globals */
        .section-dark {
            background-color: #0b1220; /* Deep blue-ish dark */
            color: #f1f5f9;
        }
        .section-dark .text-muted {
            color: #9aa4b2 !important;
        }
        .section-dark .kicker {
            color: var(--gold);
        }

        /* Hero */
        .hero{
            background: #001233; /* Very dark BSI blue */
            background-image: 
                radial-gradient(1200px 600px at 10% 10%, rgba(0,32,91, 0.4), transparent 55%),
                radial-gradient(900px 500px at 90% 20%, rgba(212,175,55, 0.08), transparent 60%);
            color: #ffffff;
            border-bottom: 0;
            padding-top: 6rem;
            padding-bottom: 6rem;
        }
        .hero .text-muted { color: #a5b4fc !important; }
        .hero .kicker { color: var(--gold); }

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

        /* BSI Brand Logos Theme Logic */
        .brand-dark { display: none; }
        @media (prefers-color-scheme: dark) {
            .brand-light { display: none; }
            .brand-dark { display: inline-block; }
        }
        html[data-theme="light"] .brand-light { display: inline-block !important; }
        html[data-theme="light"] .brand-dark { display: none !important; }
        html[data-theme="dark"] .brand-light { display: none !important; }
        html[data-theme="dark"] .brand-dark { display: inline-block !important; }

        /* Anbima Logos Theme Logic */
        .anbima-dark { display: none; }
        @media (prefers-color-scheme: dark) {
            .anbima-light { display: none; }
            .anbima-dark { display: inline-block; }
        }
        
        html[data-theme="light"] .anbima-light { display: inline-block !important; }
        html[data-theme="light"] .anbima-dark { display: none !important; }
        
        html[data-theme="dark"] .anbima-light { display: none !important; }
        html[data-theme="dark"] .anbima-dark { display: inline-block !important; }

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
        <a class="navbar-brand fw-bold" href="{{ route('site.home') }}">
            <img src="https://bsicapital.com.br/wp-content/uploads/2022/05/logo-mob.png" alt="BSI Capital" class="brand-light" style="max-height: 48px;">
            <img src="https://bsicapital.com.br/wp-content/uploads/2022/06/logo.png" alt="BSI Capital" class="brand-dark" style="max-height: 48px;">
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="nav">
            <ul class="navbar-nav ms-auto gap-lg-2 align-items-lg-center">

                <li class="nav-item">
                    <a class="nav-link" href="{{ route('site.emissions') }}">Emissões</a>
                </li>

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
                                <a class="mega-link" href="{{ route('site.imobiliario.cri') }}">CRI / Real Estate</a>
                                <a class="mega-link" href="{{ route('site.imobiliario.loteamentos') }}">Loteamentos</a>
                                <a class="mega-link" href="{{ route('site.imobiliario.incorporacao') }}">Incorporação</a>
                            </div>

                            <div class="col-lg-4 p-3">
                                <div class="mega-title">Agronegócio</div>
                                <div class="mega-kicker">Crédito estruturado para cadeias e projetos.</div>
                                <a class="mega-link" href="{{ route('site.agronegocio.cra') }}">CRA</a>
                                <a class="mega-link" href="{{ route('site.agronegocio.cooperativas') }}">Cooperativas</a>
                                <a class="mega-link" href="{{ route('site.agronegocio.projetos') }}">Projetos</a>
                            </div>

                            <div class="col-lg-4 p-3">
                                <div class="mega-title">Infra & Empresas</div>
                                <div class="mega-kicker">Estruturas para expansão e investimentos.</div>
                                <a class="mega-link" href="{{ route('site.infra.cr') }}">CR (futuro)</a>
                                <a class="mega-link" href="{{ route('site.infra.recebiveis') }}">Recebíveis</a>
                                <a class="mega-link" href="{{ route('site.infra.estruturacao') }}">Estruturação sob medida</a>
                            </div>
                        </div>
                    </div>
                </li>

                {{-- Serviços --}}
                <li class="nav-item dropdown dropdown-mega">
                    <a class="nav-link dropdown-toggle" href="{{ route('site.services') }}" role="button" data-bs-toggle="dropdown">
                        Serviços
                    </a>
                    <div class="dropdown-menu mega-menu p-0 border-0">
                        <div class="row g-3">
                            <div class="col-lg-4 p-3">
                                <div class="mega-title">Estruturação</div>
                                <div class="mega-kicker">Modelagem, documentação e governança.</div>
                                <a class="mega-link" href="{{ route('site.servicos.originacao') }}">Originação</a>
                                <a class="mega-link" href="{{ route('site.servicos.estrutura-juridica') }}">Estrutura jurídica</a>
                                <a class="mega-link" href="{{ route('site.servicos.registro-distribuicao') }}">Registro e distribuição</a>
                            </div>

                            <div class="col-lg-4 p-3">
                                <div class="mega-title">Gestão</div>
                                <div class="mega-kicker">Transparência e acompanhamento ao investidor.</div>
                                <a class="mega-link" href="{{ route('site.servicos.portal-investidor') }}">Portal do investidor</a>
                                <a class="mega-link" href="{{ route('site.servicos.relatorios') }}">Relatórios</a>
                                <a class="mega-link" href="{{ route('site.servicos.compliance') }}">Compliance</a>
                            </div>

                            <div class="col-lg-4 p-3">
                                <div class="mega-title">Tecnologia</div>
                                <div class="mega-kicker">Automação e trilha de auditoria.</div>
                                <a class="mega-link" href="{{ route('site.servicos.documentos-acl') }}">Documentos com ACL</a>
                                <a class="mega-link" href="{{ route('site.servicos.auditoria-acessos') }}">Auditoria de acessos</a>
                                <a class="mega-link" href="{{ route('site.servicos.integracoes') }}">Integrações</a>
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
                                <a class="mega-link" href="{{ route('site.about') }}">Sobre</a>
                                <a class="mega-link" href="{{ route('site.governance') }}">Governança</a>
                                <a class="mega-link" href="{{ route('site.compliance') }}">Compliance</a>
                            </div>

                            <div class="col-lg-4 p-3">
                                <div class="mega-title">Relações com Investidores</div>
                                <div class="mega-kicker">Documentos públicos e comunicados.</div>
                                <a class="mega-link" href="{{ route('site.ri') }}">R.I</a>
                                <a class="mega-link" href="{{ route('site.ri', ['category' => 'fatos_relevantes']) }}">Fatos relevantes</a>
                                <a class="mega-link" href="{{ route('site.ri', ['category' => 'assembleias']) }}">Assembleias</a>
                            </div>

                            <div class="col-lg-4 p-3">
                                <div class="mega-title">Contato</div>
                                <div class="mega-kicker">Fale com a BSI.</div>
                                <a class="mega-link" href="{{ route('site.contact') }}">Fale conosco</a>
                                <a class="mega-link" href="{{ route('site.vacancies.index') }}">Trabalhe conosco</a>
                            </div>
                        </div>
                    </div>
                </li>
            </ul>

            <div class="d-flex ms-lg-3 gap-2 align-items-center mt-3 mt-lg-0">
                <a href="{{ $portalUrl }}" class="btn btn-outline-brand btn-sm">Portal do Investidor</a>
                <a href="{{ route('site.proposal.create') }}" class="btn btn-brand btn-sm">Envie sua proposta</a>
            </div>
        </div>
    </div>
</nav>

<main class="flex-grow-1">
    @yield('content')
</main>

<footer class="footer py-5 mt-5">
    <div class="container">
        <div class="row gy-4 align-items-center">
            <div class="col-md-4 text-center text-md-start">
                <a class="navbar-brand fw-bold mb-3 d-inline-block" href="{{ route('site.home') }}">
                    <img src="https://bsicapital.com.br/wp-content/uploads/2022/05/logo-mob.png" alt="BSI Capital" class="brand-light" style="max-height: 48px;">
                    <img src="https://bsicapital.com.br/wp-content/uploads/2022/06/logo.png" alt="BSI Capital" class="brand-dark" style="max-height: 48px;">
                </a>
                <div class="d-flex gap-3 justify-content-center justify-content-md-start mt-2">
                    <a href="https://br.linkedin.com/company/bsi-capital-securitizadora-s-a" target="_blank" class="text-muted text-decoration-none" title="LinkedIn">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M19 0h-14c-2.761 0-5 2.239-5 5v14c0 2.761 2.239 5 5 5h14c2.762 0 5-2.239 5-5v-14c0-2.761-2.238-5-5-5zm-11 19h-3v-11h3v11zm-1.5-12.268c-.966 0-1.75-.79-1.75-1.764s.784-1.764 1.75-1.764 1.75.79 1.75 1.764-.783 1.764-1.75 1.764zm13.5 12.268h-3v-5.604c0-3.368-4-3.113-4 0v5.604h-3v-11h3v1.765c1.396-2.586 7-2.777 7 2.476v6.759z"/></svg>
                    </a>
                    <a href="https://www.instagram.com/bsicapitalsec/" target="_blank" class="text-muted text-decoration-none" title="Instagram">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
                    </a>
                    <a href="https://www.youtube.com/@BSICapitalSecuritizadora" target="_blank" class="text-muted text-decoration-none" title="YouTube">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>
                    </a>
                </div>
            </div>
            
            <div class="col-md-4 text-center">
                <div class="d-flex flex-column gap-2 fw-medium">
                    <a href="{{ route('site.emissions') }}" class="text-muted text-decoration-none">Ver Emissões</a>
                    <a href="{{ route('site.ri') }}" class="text-muted text-decoration-none">Relações com Investidores</a>
                    <a href="{{ route('site.proposal.create') }}" class="text-muted text-decoration-none">Envie sua proposta</a>
                    <a href="{{ route('site.vacancies.index') }}" class="text-muted text-decoration-none">Trabalhe Conosco</a>
                </div>
            </div>

            <div class="col-md-4 text-center text-md-end">
                <!-- Theme responsive ANBIMA logos -->
                <img src="https://www.anbima.com.br/lumis-theme/br/com/anbima/portal/theme/portal-anbima/assets/img/selos-anbima/ofertas-securitizadora-provisorio.jpg" class="img-fluid anbima-light" alt="Selo ANBIMA Securitizadora (Claro)" style="max-height: 80px; border-radius: 4px;">
                <img src="https://sosu.com.br/wp-content/uploads/2025/04/securitizadoras-adesao-provisoria-pagina-3-300x225.png" class="img-fluid anbima-dark" alt="Selo ANBIMA Securitizadora (Escuro)" style="max-height: 80px; border-radius: 4px;">
            </div>
        </div>
        
        <hr class="my-4 border-secondary opacity-25">
        
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-2 small pb-2">
            <div>© {{ date('Y') }} BSI Capital Securitizadora S.A. Todos os direitos reservados.</div>
            <div>Ambiente local • Versão MVP</div>
        </div>
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