<nav class="navbar navbar-expand-lg sticky-top">
    <div class="container py-2">
        <a class="navbar-brand fw-bold" href="<?php echo e(route('site.home')); ?>">
            <img src="<?php echo e(asset('images/logo-mob.png')); ?>" alt="BSI Capital">
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-controls="nav" aria-expanded="false" aria-label="Alternar navegação">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="nav">
            <ul class="navbar-nav ms-auto gap-lg-2 align-items-lg-center">

                <li class="nav-item">
                    <a class="nav-link <?php echo e(request()->routeIs('site.emissions*') ? 'active' : ''); ?>" href="<?php echo e(route('site.emissions')); ?>">Emissões</a>
                </li>

                
                <li class="nav-item dropdown dropdown-mega">
                    <a class="nav-link dropdown-toggle <?php echo e(request()->routeIs('site.imobiliario.*', 'site.agronegocio.*', 'site.infra.*') ? 'active' : ''); ?>" href="#" role="button" data-bs-toggle="dropdown">
                        Soluções
                    </a>
                    <div class="dropdown-menu mega-menu p-0 border-0">
                        <div class="row g-3">
                            <div class="col-lg-4 p-3">
                                <div class="mega-title">Imobiliário</div>
                                <div class="mega-kicker">Operações lastreadas e estruturação completa.</div>
                                <a class="mega-link" href="<?php echo e(route('site.imobiliario.cri')); ?>">CRI / Real Estate</a>
                                <a class="mega-link" href="<?php echo e(route('site.imobiliario.loteamentos')); ?>">Loteamentos</a>
                                <a class="mega-link" href="<?php echo e(route('site.imobiliario.incorporacao')); ?>">Incorporação</a>
                            </div>

                            <div class="col-lg-4 p-3">
                                <div class="mega-title">Agronegócio</div>
                                <div class="mega-kicker">Crédito estruturado para cadeias e projetos.</div>
                                <a class="mega-link" href="<?php echo e(route('site.agronegocio.cra')); ?>">CRA</a>
                                <a class="mega-link" href="<?php echo e(route('site.agronegocio.cooperativas')); ?>">Cooperativas</a>
                                <a class="mega-link" href="<?php echo e(route('site.agronegocio.projetos')); ?>">Projetos</a>
                            </div>

                            <div class="col-lg-4 p-3">
                                <div class="mega-title">Infra & Empresas</div>
                                <div class="mega-kicker">Estruturas para expansão e investimentos.</div>
                                <a class="mega-link" href="<?php echo e(route('site.infra.cr')); ?>">CR</a>
                                <a class="mega-link" href="<?php echo e(route('site.infra.recebiveis')); ?>">Recebíveis</a>
                                <a class="mega-link" href="<?php echo e(route('site.infra.estruturacao')); ?>">Estruturação sob medida</a>
                            </div>
                        </div>
                    </div>
                </li>

                
                <li class="nav-item dropdown dropdown-mega">
                    <a class="nav-link dropdown-toggle <?php echo e(request()->routeIs('site.services', 'site.servicos.*') ? 'active' : ''); ?>" href="<?php echo e(route('site.services')); ?>" role="button" data-bs-toggle="dropdown">
                        Serviços
                    </a>
                    <div class="dropdown-menu mega-menu p-0 border-0">
                        <div class="row g-3">
                            <div class="col-lg-4 p-3">
                                <div class="mega-title">Estruturação</div>
                                <div class="mega-kicker">Modelagem, documentação e governança.</div>
                                <a class="mega-link" href="<?php echo e(route('site.servicos.originacao')); ?>">Originação</a>
                                <a class="mega-link" href="<?php echo e(route('site.servicos.estrutura-juridica')); ?>">Estrutura jurídica</a>
                                <a class="mega-link" href="<?php echo e(route('site.servicos.registro-distribuicao')); ?>">Registro e distribuição</a>
                            </div>

                            <div class="col-lg-4 p-3">
                                <div class="mega-title">Gestão</div>
                                <div class="mega-kicker">Transparência e acompanhamento ao investidor.</div>
                                <a class="mega-link" href="<?php echo e(route('site.servicos.portal-investidor')); ?>">Portal do investidor</a>
                                <a class="mega-link" href="<?php echo e(route('site.servicos.relatorios')); ?>">Relatórios</a>
                                <a class="mega-link" href="<?php echo e(route('site.servicos.monitoramento-regulatorio')); ?>">Monitoramento regulatório</a>
                            </div>

                            <div class="col-lg-4 p-3">
                                <div class="mega-title">Tecnologia</div>
                                <div class="mega-kicker">Automação e trilha de auditoria.</div>
                                <a class="mega-link" href="<?php echo e(route('site.servicos.documentos-acl')); ?>">Documentos com ACL</a>
                                <a class="mega-link" href="<?php echo e(route('site.servicos.auditoria-acessos')); ?>">Auditoria de acessos</a>
                                <a class="mega-link" href="<?php echo e(route('site.servicos.integracoes')); ?>">Integrações</a>
                            </div>
                        </div>
                    </div>
                </li>

                
                <li class="nav-item dropdown dropdown-mega">
                    <a class="nav-link dropdown-toggle <?php echo e(request()->routeIs('site.about', 'site.partnerships', 'site.governance', 'site.compliance', 'site.ri', 'site.contact') ? 'active' : ''); ?>" href="#" role="button" data-bs-toggle="dropdown">
                        Institucional
                    </a>
                    <div class="dropdown-menu mega-menu p-0 border-0">
                        <div class="row g-3">
                            <div class="col-lg-4 p-3">
                                <div class="mega-title">A BSI</div>
                                <div class="mega-kicker">História, time e visão.</div>
                                <a class="mega-link" href="<?php echo e(route('site.about')); ?>">Sobre</a>
                                <a class="mega-link" href="<?php echo e(route('site.governance')); ?>">Governança</a>
                                <a class="mega-link" href="<?php echo e(route('site.compliance')); ?>">Compliance</a>
                            </div>

                            <div class="col-lg-4 p-3">
                                <div class="mega-title">Relações com Investidores</div>
                                <div class="mega-kicker">Documentos públicos e comunicados.</div>
                                <a class="mega-link" href="<?php echo e(route('site.ri')); ?>">R.I</a>
                                <a class="mega-link" href="<?php echo e(route('site.ri', ['category' => 'fatos_relevantes'])); ?>">Fatos relevantes</a>
                                <a class="mega-link" href="<?php echo e(route('site.ri', ['category' => 'assembleias'])); ?>">Assembleias</a>
                            </div>

                            <div class="col-lg-4 p-3">
                                <div class="mega-title">Contato</div>
                                <div class="mega-kicker">Fale com a BSI.</div>
                                <a class="mega-link" href="<?php echo e(route('site.contact')); ?>">Fale conosco</a>
                                <a class="mega-link" href="<?php echo e(route('site.partnerships')); ?>">Parcerias</a>
                                <a class="mega-link" href="<?php echo e(route('site.vacancies.index')); ?>">Trabalhe conosco</a>
                            </div>
                        </div>
                    </div>
                </li>


            </ul>

            <div class="d-flex ms-lg-3 gap-2 align-items-center mt-3 mt-lg-0 ps-lg-3" style="border-left: 1px solid color-mix(in srgb, var(--gold) 18%, var(--border));">
                <a href="<?php echo e($portalUrl); ?>" class="btn btn-outline-brand btn-sm">Portal do Investidor</a>
                <a href="<?php echo e(route('proposal.create')); ?>" class="btn btn-brand btn-sm">Envie sua proposta</a>
            </div>
        </div>
    </div>
</nav>
<?php /**PATH /home/desktop/projects/bsi-capital/resources/views/site/partials/navbar.blade.php ENDPATH**/ ?>