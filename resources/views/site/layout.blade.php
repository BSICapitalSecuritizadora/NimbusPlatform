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
    --gold: #ffc20e;         /* dourado accent */

    /* Light (Opea-like) */
    --bg: #f7f8fb;
    --surface: #ffffff;
    --text: #0b1220;
    --muted: #5b667a;
    --border: #e7ebf3;

    --nav-bg: rgba(255,255,255,.90);
    --hero-1: rgba(0,32,91,.14);
    --hero-2: rgba(0,32,91,.06);

    --link: var(--brand);
  }

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

      --link: #ffffff;
    }
  }

  /* manual override (se você estiver usando o toggle) */
  html[data-theme="light"]{
    --brand: #00205b;
    --gold: #ffc20e;
    --bg: #f7f8fb;
    --surface: #ffffff;
    --text: #0b1220;
    --muted: #5b667a;
    --border: #e7ebf3;
    --nav-bg: rgba(255,255,255,.90);
    --hero-1: rgba(0,32,91,.14);
    --hero-2: rgba(0,32,91,.06);
    --link: var(--brand);
  }
  html[data-theme="dark"]{
    --brand: #00205b;
    --gold: #ffc20e;
    --bg: #070a0f;
    --surface: #0b0f17;
    --text: #f1f5f9;
    --muted: #9aa4b2;
    --border: rgba(255,255,255,.10);
    --nav-bg: rgba(11,15,23,.75);
    --hero-1: rgba(255,255,255,.06);
    --hero-2: rgba(255,194,14,.10);
    --link: #ffffff;
  }

  body{
    font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
    background: var(--bg);
    color: var(--text);
  }

  .navbar{
    background: var(--nav-bg);
    backdrop-filter: blur(10px);
    border-bottom: 1px solid var(--border);
  }

  .nav-link{ color: color-mix(in oklab, var(--text) 85%, transparent); }
  .nav-link:hover{ color: var(--brand); }

  .card{
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
  }

  .text-muted{ color: var(--muted) !important; }

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

  /* dourado só em hover/badges */
  .badge-soft{
    background: color-mix(in oklab, var(--gold) 14%, transparent);
    border: 1px solid color-mix(in oklab, var(--gold) 24%, var(--border) 76%);
    color: color-mix(in oklab, var(--text) 70%, var(--brand) 30%);
    font-weight: 600;
  }

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

  .logo-strip img{
    filter: grayscale(1);
    opacity: .75;
    max-height: 28px;
  }
  .logo-strip img:hover{
    filter: grayscale(0);
    opacity: 1;
  }

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
  .mega-kicker{
    font-size:.82rem;
    color: var(--muted);
    margin-bottom: 10px;
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
                      <div class="mega-kicker">Estratégias para crédito e cadeias produtivas.</div>
                      <a class="mega-link" href="#">CRA</a>
                      <a class="mega-link" href="#">Cooperativas</a>
                      <a class="mega-link" href="#">Projetos</a>
                    </div>
                    <div class="col-lg-4 p-3">
                      <div class="mega-title">Infra & Empresas</div>
                      <div class="mega-kicker">Estruturas para expansão e investimentos.</div>
                      <a class="mega-link" href="#">CR / Debêntures (futuro)</a>
                      <a class="mega-link" href="#">Fluxo de recebíveis</a>
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
                      <div class="mega-kicker">Acompanhamento e transparência ao investidor.</div>
                      <a class="mega-link" href="#">Portal do investidor</a>
                      <a class="mega-link" href="#">Relatórios</a>
                      <a class="mega-link" href="#">Compliance</a>
                    </div>
                    <div class="col-lg-4 p-3">
                      <div class="mega-title">Tecnologia</div>
                      <div class="mega-kicker">Automação de processos e controle de acesso.</div>
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

            <div class="d-flex ms-lg-3 gap-2">
                @php
                  $portalUrl = config('app.portal_url', '/investidor/login');
                @endphp
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
@stack('scripts')


</body>
</html>