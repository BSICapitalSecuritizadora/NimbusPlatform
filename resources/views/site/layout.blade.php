<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'BSI Capital')</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        :root{
            --brand: #00205b;        /* azul institucional */
            --brand-600: #ffc20e;    /* dourado destaque */
            --ink: #0f172a;
            --muted: #64748b;
            --bg: #f6f8fb;
            --card: #ffffff;
            --border: #e6eaf2;
        }

        body{
            font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
            background: var(--bg);
            color: var(--ink);
        }

        .navbar{
            background: rgba(255,255,255,.92);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border);
        }

        .btn-brand{
            background: var(--brand);
            border-color: var(--brand);
            color: #fff;
        }
        .btn-brand:hover{ opacity:.95; color:#fff; }

        .btn-outline-brand{
            border-color: var(--brand);
            color: var(--brand);
        }
        .btn-outline-brand:hover{
            background: var(--brand);
            color:#fff;
        }

        .hero{
            background: radial-gradient(1200px 600px at 10% 10%, rgba(0,32,91,.18), transparent 55%),
                        radial-gradient(900px 500px at 90% 20%, rgba(255,194,14,.18), transparent 60%),
                        #ffffff;
            border-bottom: 1px solid var(--border);
        }

        .hero-kicker{
            color: var(--muted);
            font-weight: 600;
            letter-spacing: .04em;
            text-transform: uppercase;
            font-size: .80rem;
        }

        .section-title{
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .card{
            border: 1px solid var(--border);
            border-radius: 14px;
        }

        .badge-soft{
            background: rgba(0,32,91,.08);
            color: var(--brand);
            border: 1px solid rgba(0,32,91,.12);
            font-weight: 600;
        }

        .footer{
            border-top: 1px solid var(--border);
            color: var(--muted);
        }
    </style>

    @stack('head')
</head>
<body>
<nav class="navbar navbar-expand-lg sticky-top">
    <div class="container py-2">
        <a class="navbar-brand fw-bold" href="{{ route('site.home') }}" style="color:var(--brand)">
            BSI Capital
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="nav">
            <ul class="navbar-nav ms-auto gap-lg-2">
                <li class="nav-item"><a class="nav-link" href="#">Serviços</a></li>
                <li class="nav-item"><a class="nav-link" href="#">Emissões</a></li>
                <li class="nav-item"><a class="nav-link" href="#">R.I</a></li>
                <li class="nav-item"><a class="nav-link" href="#">Governança</a></li>
                <li class="nav-item"><a class="nav-link" href="#">Contato</a></li>
            </ul>

            <div class="d-flex ms-lg-3 gap-2">
                <a href="/portal" class="btn btn-outline-brand btn-sm">Portal do Investidor</a>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
@stack('scripts')
</body>
</html>