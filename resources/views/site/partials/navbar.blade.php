<nav class="navbar navbar-expand-lg sticky-top">
    <div class="container py-2">
        <a href="{{ route('site.home') }}" class="site-brand" aria-label="BSI Capital">
            <img src="{{ asset('images/bsi-logo.png') }}" alt="BSI Capital Securitizadora" class="site-logo">
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-controls="nav" aria-expanded="false" aria-label="Alternar navegação">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="nav">
            <ul class="navbar-nav ms-auto gap-lg-2 align-items-lg-center">

                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('site.emissions*') ? 'active' : '' }}" href="{{ route('site.emissions') }}">Emissões</a>
                </li>

                {{-- Soluções --}}
                <li class="nav-item dropdown dropdown-mega">
                    <a class="nav-link dropdown-toggle {{ request()->routeIs('site.imobiliario.*', 'site.agronegocio.*', 'site.infra.*') ? 'active' : '' }}" href="#" role="button" data-bs-toggle="dropdown">
                        Soluções
                    </a>
                    <div class="dropdown-menu mega-menu p-0 border-0">
                        <div class="row g-3">
                            <div class="col-lg-4 p-3">
                                <div class="mega-title mb-3">Imobiliário</div>

                                <a class="mega-link" href="{{ route('site.imobiliario.cri') }}">CRI / Real Estate</a>
                                <a class="mega-link" href="{{ route('site.imobiliario.loteamentos') }}">Loteamentos</a>
                                <a class="mega-link" href="{{ route('site.imobiliario.incorporacao') }}">Incorporação</a>
                            </div>

                            <div class="col-lg-4 p-3">
                                <div class="mega-title mb-3">Agronegócio</div>

                                <a class="mega-link" href="{{ route('site.agronegocio.cra') }}">CRA</a>
                                <a class="mega-link" href="{{ route('site.agronegocio.cooperativas') }}">Cooperativas</a>
                                <a class="mega-link" href="{{ route('site.agronegocio.projetos') }}">Projetos</a>
                            </div>

                            <div class="col-lg-4 p-3">
                                <div class="mega-title mb-3">Infra & Empresas</div>

                                <a class="mega-link" href="{{ route('site.infra.cr') }}">CR</a>
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
                                <a class="mega-link" href="{{ route('site.servicos.originacao') }}">Originação</a>
                                <a class="mega-link" href="{{ route('site.servicos.estrutura-juridica') }}">Estrutura jurídica</a>
                                <a class="mega-link" href="{{ route('site.servicos.registro-distribuicao') }}">Registro e distribuição</a>
                            </div>

                            <div class="col-lg-4 p-3">
                                <div class="mega-title">Gestão</div>
                                <a class="mega-link" href="{{ route('site.servicos.portal-investidor') }}">Portal do investidor</a>
                                <a class="mega-link" href="{{ route('site.servicos.relatorios') }}">Relatórios fiduciários</a>
                                <a class="mega-link" href="{{ route('site.servicos.monitoramento-regulatorio') }}">Monitoramento regulatório</a>
                            </div>

                            <div class="col-lg-4 p-3">
                                <div class="mega-title">Tecnologia</div>
                                <a class="mega-link" href="{{ route('site.servicos.documentos-acl') }}">Documentos com ACL</a>
                                <a class="mega-link" href="{{ route('site.servicos.auditoria-acessos') }}">Auditoria de acessos</a>
                                <a class="mega-link" href="{{ route('site.servicos.integracoes') }}">Integrações</a>
                            </div>
                        </div>
                    </div>
                </li>

                {{-- Institucional --}}
                <li class="nav-item dropdown dropdown-mega">
                    <a class="nav-link dropdown-toggle {{ request()->routeIs('site.about', 'site.partnerships', 'site.governance', 'site.compliance', 'site.ri', 'site.contact') ? 'active' : '' }}" href="#" role="button" data-bs-toggle="dropdown">
                        Institucional
                    </a>
                    <div class="dropdown-menu mega-menu p-0 border-0">
                        <div class="row g-3">
                            <div class="col-lg-4 p-3">
                                <div class="mega-title">A BSI</div>
                                <a class="mega-link" href="{{ route('site.about') }}">Sobre</a>
                                <a class="mega-link" href="{{ route('site.governance') }}">Governança</a>
                                <a class="mega-link" href="{{ route('site.compliance') }}">Compliance</a>
                            </div>

                            <div class="col-lg-4 p-3">
                                <div class="mega-title">Relações com Investidores</div>
                                <a class="mega-link" href="{{ route('site.ri') }}">Portal do Investidor</a>
                                <a class="mega-link" href="{{ route('site.intelligence') }}">Intelligence (Análises)</a>
                                <a class="mega-link" href="{{ route('site.ri', ['category' => 'assembleias']) }}">Assembleias</a>
                            </div>

                            <div class="col-lg-4 p-3">
                                <div class="mega-title">Contato</div>
                                <a class="mega-link" href="{{ route('site.contact') }}">Fale conosco</a>
                                <a class="mega-link" href="{{ route('site.partnerships') }}">Parcerias</a>
                                <a class="mega-link" href="{{ route('site.vacancies.index') }}">Trabalhe conosco</a>
                            </div>
                        </div>
                    </div>
                </li>


            </ul>

            <div class="d-flex ms-lg-3 gap-2 align-items-center mt-3 mt-lg-0 ps-lg-3" style="border-left: 1px solid color-mix(in srgb, var(--gold) 18%, transparent);">
                <a href="{{ $portalUrl }}" class="btn btn-outline-gold btn-sm">Portal do Investidor</a>
                <a href="{{ route('proposal.create') }}" class="btn btn-brand btn-sm">Solicitar Análise</a>
            </div>
        </div>
    </div>
</nav>
