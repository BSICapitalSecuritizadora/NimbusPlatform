<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>
        @isset($title)
            {{ $title }}
        @else
            @yield('title', 'BSI Capital')
        @endisset
    </title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        :root {
            --brand: #00205b;
            --brand-strong: #001233;
            --brand-soft: #0f2f73;
            --gold: #d4af37;
            --gold-soft: #f3e4a8;
            --bg: #f2f5f9;
            --surface: #ffffff;
            --surface-alt: #f6f9fd;
            --text: #081224;
            --muted: #5c6980;
            --border: #d5deea;
            --shadow-soft: 0 14px 32px rgba(0, 32, 91, 0.07);
            --shadow-hover: 0 18px 38px rgba(0, 32, 91, 0.1);
            --nav-bg: rgba(255, 255, 255, 0.97);
            --brand-outline: var(--brand);
            --brand-outline-border: color-mix(in srgb, var(--brand) 18%, var(--border));
            --radius-shell: 18px;
            --radius-card: 16px;
            --radius-control: 12px;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:
                radial-gradient(880px 380px at 0% 0%, rgba(0, 32, 91, 0.06), transparent 58%),
                radial-gradient(620px 300px at 100% 4%, rgba(212, 175, 55, 0.05), transparent 58%),
                var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            letter-spacing: -0.01em;
        }

        a {
            color: inherit;
            transition: color 0.2s ease, opacity 0.2s ease, transform 0.2s ease;
        }

        a:focus-visible,
        button:focus-visible,
        .btn:focus-visible,
        .nav-link:focus-visible,
        .mega-link:focus-visible,
        .footer-link:focus-visible,
        .navbar-toggler:focus-visible,
        .form-control:focus-visible,
        .form-select:focus-visible {
            outline: none;
            box-shadow: 0 0 0 0.24rem rgba(0, 32, 91, 0.12), 0 0 0 0.42rem rgba(212, 175, 55, 0.18) !important;
        }

        img {
            max-width: 100%;
        }

        .text-muted {
            color: var(--muted) !important;
        }

        .text-brand {
            color: var(--brand) !important;
        }

        .bg-brand-subtle {
            background: color-mix(in srgb, var(--brand) 7%, var(--surface));
        }

        .border-brand-subtle {
            border-color: color-mix(in srgb, var(--brand) 14%, var(--border)) !important;
        }

        .site-main {
            position: relative;
            flex: 1 1 auto;
            overflow: hidden;
        }

        .site-main::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 180px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.12), transparent);
            pointer-events: none;
        }

        .section-copy {
            color: var(--muted);
            line-height: 1.75;
        }

        .section-divider {
            width: 64px;
            height: 3px;
            border-radius: 999px;
            background: linear-gradient(90deg, var(--gold), color-mix(in srgb, var(--gold) 35%, var(--brand) 65%), var(--brand));
        }

        .section-kicker,
        .kicker {
            color: var(--gold);
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            font-size: 0.78rem;
        }

        .surface-card,
        .card {
            background: color-mix(in srgb, var(--surface) 97%, var(--brand) 3%);
            border: 1px solid var(--border);
            border-radius: var(--radius-card);
            color: var(--text);
            box-shadow: var(--shadow-soft);
        }

        .card-opea,
        .surface-card-soft {
            background: color-mix(in srgb, var(--surface-alt) 94%, var(--brand) 6%);
        }

        .surface-card-dark {
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.08), rgba(255, 255, 255, 0.04));
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #fff;
            border-radius: var(--radius-card);
        }

        .hero-metric-value {
            font-size: clamp(1.5rem, 1.2rem + 0.9vw, 2rem);
            line-height: 1.08;
            letter-spacing: -0.04em;
            max-width: 100%;
            overflow-wrap: anywhere;
            text-wrap: balance;
        }

        .card-hover,
        .hover-lift,
        .emission-card,
        .ri-item {
            transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease, background 0.25s ease;
        }

        .card-hover:hover,
        .hover-lift:hover,
        .emission-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-hover) !important;
            border-color: color-mix(in srgb, var(--gold) 22%, var(--border));
        }

        .ri-item:hover {
            background: color-mix(in srgb, var(--brand) 3%, var(--surface)) !important;
        }

        .badge {
            border-radius: 999px;
            font-weight: 700;
            letter-spacing: 0.05em;
        }

        .badge-soft {
            background: var(--gold);
            border: 1px solid var(--gold);
            color: var(--brand);
            font-weight: 700;
        }

        .btn {
            border-radius: 14px;
            padding: 0.72rem 1.2rem;
            font-weight: 600;
            letter-spacing: -0.01em;
            transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, border-color 0.2s ease, background 0.2s ease;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-brand {
            background: linear-gradient(135deg, var(--brand), color-mix(in srgb, var(--brand) 82%, black));
            border-color: var(--brand);
            color: #fff;
            box-shadow: 0 10px 24px rgba(0, 32, 91, 0.16);
        }

        .btn-brand:hover,
        .btn-brand:focus {
            color: #fff;
            background: linear-gradient(135deg, color-mix(in srgb, var(--brand) 88%, black), var(--brand-strong));
            border-color: var(--brand-strong);
        }

        .btn-outline-brand {
            border-color: var(--brand-outline-border);
            color: var(--brand-outline);
            background: color-mix(in srgb, var(--surface) 88%, transparent);
        }

        .btn-outline-brand:hover,
        .btn-outline-brand:focus {
            color: var(--text);
            border-color: color-mix(in srgb, var(--gold) 45%, var(--brand));
            background: color-mix(in srgb, var(--gold) 14%, var(--surface));
        }

        .btn-outline-gold {
            border-color: color-mix(in srgb, var(--gold) 45%, transparent);
            color: var(--gold);
            background: transparent;
        }

        .btn-outline-gold:hover,
        .btn-outline-gold:focus {
            color: var(--brand);
            border-color: var(--gold);
            background: var(--gold);
        }

        .btn-light {
            background: color-mix(in srgb, var(--surface) 92%, white 8%);
            color: var(--brand);
            border-color: color-mix(in srgb, var(--border) 85%, white);
            box-shadow: 0 8px 22px rgba(0, 0, 0, 0.05);
        }

        .btn-light:hover,
        .btn-light:focus {
            color: var(--brand);
            border-color: color-mix(in srgb, var(--gold) 30%, var(--border));
            background: color-mix(in srgb, var(--surface) 76%, var(--gold) 24%);
        }

        .form-control,
        .form-select,
        textarea.form-control {
            border-radius: var(--radius-control);
            border: 1px solid color-mix(in srgb, var(--border) 86%, var(--brand) 14%);
            background: color-mix(in srgb, var(--surface) 96%, var(--brand) 4%);
            color: var(--text);
            padding: 0.82rem 0.95rem;
            box-shadow: none !important;
        }

        .form-control::placeholder,
        .form-select,
        textarea.form-control::placeholder {
            color: color-mix(in srgb, var(--muted) 84%, transparent);
        }

        .form-control:focus,
        .form-select:focus,
        textarea.form-control:focus {
            border-color: color-mix(in srgb, var(--gold) 30%, var(--brand) 70%);
            background: color-mix(in srgb, var(--surface) 98%, white 2%);
            box-shadow: 0 0 0 0.22rem rgba(0, 32, 91, 0.08) !important;
            color: var(--text);
        }

        .input-group-text {
            border-radius: var(--radius-control);
            border: 1px solid color-mix(in srgb, var(--border) 86%, var(--brand) 14%);
            background: color-mix(in srgb, var(--surface) 96%, var(--brand) 4%);
            color: var(--muted);
        }

        .form-label {
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--muted);
        }

        .list-group-item {
            color: var(--text);
        }

        .table {
            --bs-table-bg: transparent;
            --bs-table-border-color: var(--border);
            color: var(--text);
        }

        .table > :not(caption) > * > * {
            padding: 1rem 1.1rem;
        }

        .table thead th {
            background: color-mix(in srgb, var(--brand) 7%, var(--surface));
            color: var(--brand);
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            border-bottom-width: 1px;
        }

        .table-shell {
            overflow: hidden;
            border-radius: var(--radius-card);
            border: 1px solid var(--border);
            background: color-mix(in srgb, var(--surface) 96%, var(--brand) 4%);
            box-shadow: var(--shadow-soft);
        }

        .pagination {
            gap: 0.55rem;
            margin-top: 2rem;
            justify-content: center;
        }

        .pagination .page-item .page-link {
            width: 44px;
            height: 44px;
            border-radius: 14px !important;
            border: 1px solid var(--border);
            background: color-mix(in srgb, var(--surface) 95%, var(--brand) 5%);
            color: var(--muted);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: none;
            font-weight: 600;
        }

        .pagination .page-item:first-child .page-link,
        .pagination .page-item:last-child .page-link {
            width: auto;
            min-width: 44px;
            padding-inline: 1rem;
        }

        .pagination .page-item.active .page-link {
            background: var(--brand);
            border-color: var(--brand);
            color: #fff;
            box-shadow: 0 12px 30px rgba(0, 32, 91, 0.18);
        }

        .pagination .page-item:not(.active) .page-link:hover {
            border-color: color-mix(in srgb, var(--gold) 35%, var(--brand));
            color: var(--brand);
            background: color-mix(in srgb, var(--gold) 12%, var(--surface));
        }

        .pagination .page-item.disabled .page-link {
            background: color-mix(in srgb, var(--surface) 88%, var(--border) 12%);
            color: color-mix(in srgb, var(--muted) 68%, transparent);
        }

        .result-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border-radius: 999px;
            padding: 0.55rem 0.85rem;
            border: 1px solid color-mix(in srgb, var(--brand) 10%, var(--border));
            background: color-mix(in srgb, var(--surface) 94%, var(--brand) 6%);
            color: var(--brand);
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: 0.03em;
        }

        .navbar {
            background: var(--nav-bg);
            backdrop-filter: blur(10px);
            border: 1px solid color-mix(in srgb, var(--border) 92%, white 8%);
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.05);
            border-radius: var(--radius-shell);
            max-width: 1220px;
            margin: 1rem auto 0;
            top: 0.85rem;
            padding: 0.15rem 0.35rem;
        }

        .navbar .container {
            max-width: 1160px;
        }

        .navbar-brand {
            color: var(--brand) !important;
            letter-spacing: -0.03em;
        }

        .navbar-brand img {
            max-height: 44px;
            width: auto;
        }

        .navbar-toggler {
            border: 1px solid color-mix(in srgb, var(--border) 88%, var(--brand) 12%);
            border-radius: 16px;
            padding: 0.45rem 0.65rem;
        }

        .navbar-toggler:focus {
            box-shadow: 0 0 0 0.2rem rgba(0, 32, 91, 0.08);
        }

        .navbar-toggler-icon {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3E%3Cpath stroke='rgba(0, 32, 91, 0.85)' stroke-width='2' stroke-linecap='round' d='M4 7h22M4 15h22M4 23h22'/%3E%3C/svg%3E");
        }

        .nav-link {
            position: relative;
            color: color-mix(in srgb, var(--text) 86%, transparent) !important;
            font-weight: 600;
            padding: 0.7rem 0.85rem !important;
            border-radius: 10px;
        }

        .nav-link:hover,
        .nav-link.active {
            color: var(--brand) !important;
            background: color-mix(in srgb, var(--brand) 7%, transparent);
        }

        .nav-link.active::after {
            content: "";
            position: absolute;
            left: 0.85rem;
            right: 0.85rem;
            bottom: 0.45rem;
            height: 2px;
            border-radius: 999px;
            background: linear-gradient(90deg, var(--gold), var(--brand));
        }

        .dropdown-mega {
            position: static;
        }

        .mega-menu {
            width: min(1080px, 96vw);
            margin-top: 0.8rem;
            border: 1px solid var(--border);
            background: color-mix(in srgb, var(--surface) 98%, var(--brand) 2%);
            border-radius: var(--radius-card);
            padding: 0.8rem;
            box-shadow: 0 18px 42px rgba(0, 0, 0, 0.1);
        }

        .mega-menu .col-lg-4 {
            border-radius: 12px;
            background: color-mix(in srgb, var(--surface-alt) 94%, var(--brand) 6%);
            border: 1px solid color-mix(in srgb, var(--brand) 8%, var(--border));
        }

        .mega-title {
            font-weight: 800;
            letter-spacing: -0.02em;
            margin-bottom: 0.35rem;
            color: var(--brand);
        }

        .mega-kicker {
            font-size: 0.86rem;
            color: var(--muted);
            margin-bottom: 1rem;
            line-height: 1.6;
        }

        .mega-link {
            display: block;
            padding: 0.65rem 0.8rem;
            border-radius: 10px;
            color: var(--text);
            text-decoration: none;
            font-weight: 600;
        }

        .mega-link:hover {
            background: color-mix(in srgb, var(--brand) 7%, transparent);
            color: var(--brand);
        }

        .hero {
            position: relative;
            overflow: hidden;
            background:
                radial-gradient(1200px 520px at 8% 10%, rgba(212, 175, 55, 0.12), transparent 60%),
                radial-gradient(950px 460px at 95% 0%, rgba(255, 255, 255, 0.08), transparent 58%),
                linear-gradient(145deg, var(--brand-strong), #031f52 58%, #0a1734 100%);
            color: #fff;
            border-bottom: 0;
            padding-top: 6rem;
            padding-bottom: 6rem;
        }

        .hero::before {
            content: "";
            position: absolute;
            inset: auto -12% -22% auto;
            width: 360px;
            height: 360px;
            background: radial-gradient(circle, rgba(212, 175, 55, 0.18), transparent 68%);
            pointer-events: none;
        }

        .hero::after {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.02), transparent 35%, rgba(0, 0, 0, 0.1));
            pointer-events: none;
        }

        .hero > .container,
        .hero > .position-relative {
            position: relative;
            z-index: 1;
        }

        .hero .badge {
            border: 1px solid rgba(212, 175, 55, 0.4);
            color: var(--gold);
            background: rgba(212, 175, 55, 0.1);
            letter-spacing: 0.12em;
        }

        .hero .display-3,
        .hero .display-4,
        .hero h1 {
            letter-spacing: -0.04em;
        }

        .hero .lead {
            color: rgba(232, 239, 255, 0.86) !important;
            line-height: 1.7;
        }

        .hero .text-muted {
            color: #b7c5dd !important;
        }

        .emission-detail-tabs {
            flex-wrap: nowrap;
            overflow-x: auto;
            padding-bottom: 0.35rem;
            scrollbar-width: none;
        }

        .emission-detail-tabs::-webkit-scrollbar {
            display: none;
        }

        .emission-detail-tabs .nav-item {
            flex: 0 0 auto;
        }

        .emission-detail-tabs .nav-link {
            white-space: nowrap;
        }

        .emission-doc-card {
            border-radius: var(--radius-card);
        }

        .section-dark {
            background:
                radial-gradient(900px 420px at 15% 0%, rgba(212, 175, 55, 0.08), transparent 60%),
                #0b1220;
            color: #f5f7fb;
        }

        .section-dark .text-muted {
            color: #92a0b8 !important;
        }

        .section-dark .card {
            background: rgba(255, 255, 255, 0.04);
            border-color: rgba(255, 255, 255, 0.08);
            color: #f5f7fb;
        }

        .footer {
            position: relative;
            overflow: hidden;
            margin-top: 5rem;
            border-top: 1px solid color-mix(in srgb, var(--gold) 12%, var(--border));
            background:
                radial-gradient(720px 260px at 0% 0%, rgba(0, 32, 91, 0.05), transparent 65%),
                linear-gradient(180deg, color-mix(in srgb, var(--surface) 94%, var(--brand) 6%), color-mix(in srgb, var(--surface) 97%, var(--brand) 3%));
            color: var(--muted);
        }

        .footer::before {
            content: "";
            position: absolute;
            top: -120px;
            right: -80px;
            width: 280px;
            height: 280px;
            background: radial-gradient(circle, rgba(212, 175, 55, 0.08), transparent 68%);
            pointer-events: none;
        }

        .footer-heading {
            margin-bottom: 1rem;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--brand);
        }

        .footer-link {
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            color: var(--muted);
            text-decoration: none;
            padding: 0.15rem 0;
        }

        .footer-link:hover {
            color: var(--brand);
        }

        .footer-seal {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            gap: 0.9rem;
            min-width: 220px;
            padding: 1rem 1.1rem;
            border: 1px solid color-mix(in srgb, var(--gold) 16%, var(--border));
            border-radius: var(--radius-card);
            background: color-mix(in srgb, var(--surface) 96%, var(--brand) 4%);
            box-shadow: var(--shadow-soft);
        }

        .footer-seal-label {
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--brand);
        }

        @media (max-width: 991.98px) {
            .navbar {
                margin: 0.9rem 0.85rem 0;
                border-radius: 16px;
            }

            .navbar .container {
                max-width: 100%;
            }

            .navbar-collapse {
                padding: 1rem 0.2rem 0.2rem;
            }

            .mega-menu {
                width: 100%;
                margin-top: 0.75rem;
            }

            .hero {
                padding-top: 5rem;
                padding-bottom: 4.5rem;
            }
        }

        @media (max-width: 767.98px) {
            .hero {
                padding-top: 4.5rem;
                padding-bottom: 4rem;
            }

            .surface-card,
            .card {
                border-radius: 14px;
            }

            .btn {
                width: auto;
            }

            .result-chip {
                font-size: 0.72rem;
                padding: 0.5rem 0.8rem;
            }

            .pagination .page-item .page-link {
                height: 42px;
                min-width: 42px;
            }

            .emission-doc-card .btn {
                width: 100%;
            }
        }
    </style>

    @stack('head')
</head>
<body>
@php
    $portalUrl = env('APP_PORTAL_URL', '/portal');
@endphp

<nav class="navbar navbar-expand-lg sticky-top">
    <div class="container py-2">
        <a class="navbar-brand fw-bold" href="{{ route('site.home') }}">
            <img src="https://bsicapital.com.br/wp-content/uploads/2022/05/logo-mob.png" alt="BSI Capital">
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-controls="nav" aria-expanded="false" aria-label="Alternar navegação">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="nav">
            <ul class="navbar-nav ms-auto gap-lg-2 align-items-lg-center">

                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('site.emissions*') ? 'active' : '' }}" href="{{ route('site.emissions') }}">Emissões</a>
                </li>

                {{-- Indústrias --}}
                <li class="nav-item dropdown dropdown-mega">
                    <a class="nav-link dropdown-toggle {{ request()->routeIs('site.imobiliario.*', 'site.agronegocio.*', 'site.infra.*') ? 'active' : '' }}" href="#" role="button" data-bs-toggle="dropdown">
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
                    <a class="nav-link dropdown-toggle {{ request()->routeIs('site.services', 'site.servicos.*') ? 'active' : '' }}" href="{{ route('site.services') }}" role="button" data-bs-toggle="dropdown">
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
                    <a class="nav-link dropdown-toggle {{ request()->routeIs('site.about', 'site.governance', 'site.compliance', 'site.ri', 'site.contact', 'site.vacancies.*') ? 'active' : '' }}" href="#" role="button" data-bs-toggle="dropdown">
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

            <div class="d-flex ms-lg-3 gap-2 align-items-center mt-3 mt-lg-0 ps-lg-3 border-lg-start border-brand-subtle">
                <a href="{{ $portalUrl }}" class="btn btn-outline-brand btn-sm">Portal do Investidor</a>
                <a href="{{ route('proposal.create') }}" class="btn btn-brand btn-sm">Envie sua proposta</a>
            </div>
        </div>
    </div>
</nav>

<main class="site-main">
    @isset($slot)
        {{ $slot }}
    @else
        @yield('content')
    @endisset
</main>

<footer class="footer py-5 mt-5">
    <div class="container">
        <div class="row gy-4 align-items-center">
            <div class="col-md-4 text-center text-md-start">
                <a class="navbar-brand fw-bold mb-3 d-inline-block" href="{{ route('site.home') }}">
                    <img src="https://bsicapital.com.br/wp-content/uploads/2022/05/logo-mob.png" alt="BSI Capital" style="max-height: 48px;">
                </a>
                <p class="section-copy small mb-3">
                    Securitizadora registrada na CVM. Estruturamos e gerimos operações de crédito — CRI, CRA e CR — com rigor técnico, controle documental e reporte contínuo aos investidores.
                </p>
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
                <div class="footer-heading text-center">Atalhos</div>
                <div class="d-flex flex-column gap-2 fw-medium">
                    <a href="{{ route('site.emissions') }}" class="footer-link justify-content-center">Ver Emissões</a>
                    <a href="{{ route('site.ri') }}" class="footer-link justify-content-center">Relações com Investidores</a>
                    <a href="{{ route('proposal.create') }}" class="footer-link justify-content-center">Envie sua proposta</a>
                    <a href="{{ route('site.vacancies.index') }}" class="footer-link justify-content-center">Trabalhe conosco</a>
                </div>
            </div>

            <div class="col-md-4 text-center text-md-end">
                <div class="footer-seal">
                    <div class="footer-seal-label">Autorregulação</div>
                    <img src="https://www.anbima.com.br/lumis-theme/br/com/anbima/portal/theme/portal-anbima/assets/img/selos-anbima/ofertas-securitizadora-provisorio.jpg" class="img-fluid" alt="Selo ANBIMA Securitizadora" style="max-height: 84px; border-radius: 6px;">
                </div>
            </div>
        </div>
        
        <hr class="my-4 border-brand-subtle opacity-100">

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-2 small pb-2">
            <div>© {{ date('Y') }} BSI Capital Securitizadora S.A. Todos os direitos reservados.</div>
            <div>Companhia aberta registrada na CVM e alinhada a referenciais de autorregulação aplicáveis.</div>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

@stack('scripts')
</body>
</html>
