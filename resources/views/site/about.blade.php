@extends('site.layout')
@section('title','Sobre — BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.08; background: url('{{ asset('images/compliance.png') }}') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Institucional</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Prazer, somos a <br><span style="color: var(--gold);">BSI Capital</span>
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%;">
                    Securitizadora constituída em 2009, a BSI Capital ajuda empresas a crescer conectando bons projetos ao mercado de capitais. Companhia aberta com registro na CVM, unimos a segurança do mercado tradicional à agilidade da tecnologia para estruturar operações de crédito que fazem a diferença.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="{{ route('site.contact') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Falar com Especialista
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </a>
                    <a href="{{ route('site.emissions') }}" class="btn btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3" style="border: 1px solid rgba(230,228,228,0.35); color: #E6E4E4; background: rgba(230,228,228,0.08); transition: all 0.3s ease;">
                        Ver emissões
                    </a>
                </div>
            </div>

            <div class="col-lg-6 d-none d-lg-block">
                <div class="position-relative">
                    <div style="border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
                        <img src="{{ asset('images/compliance.png') }}" class="img-fluid" alt="BSI Capital Securitizadora" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"></circle><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"></path></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">Companhia aberta</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">Registrada na CVM</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Contadores -->
<section style="background: #0b1220; border-top: 1px solid rgba(212,175,55,0.15);">
    <div class="container py-5">
        <div class="row text-center g-4">
            <div class="col-6 col-md-3">
                <div class="display-5 fw-bold" style="color: var(--gold);">2009</div>
                <div class="text-uppercase mt-2" style="color: #8892b0; font-size: 0.8rem; letter-spacing: 0.1em;">Fundação</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="display-5 fw-bold" style="color: var(--gold);">+R$ 1 Bi</div>
                <div class="text-uppercase mt-2" style="color: #8892b0; font-size: 0.8rem; letter-spacing: 0.1em;">Volume Estruturado</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="display-5 fw-bold" style="color: var(--gold);">CVM</div>
                <div class="text-uppercase mt-2" style="color: #8892b0; font-size: 0.8rem; letter-spacing: 0.1em;">Registro CVM</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="display-5 fw-bold" style="color: var(--gold);">ANBIMA</div>
                <div class="text-uppercase mt-2" style="color: #8892b0; font-size: 0.8rem; letter-spacing: 0.1em;">Autorregulação</div>
            </div>
        </div>
    </div>
</section>

<!-- Timeline de Sucessos -->
<section class="py-5 bg-white border-top position-relative overflow-hidden">
    <!-- Decoração de fundo sutil -->
    <div class="position-absolute top-0 start-0 w-100 h-100" style="background: radial-gradient(circle at top right, rgba(212,175,55,0.03), transparent 50%); pointer-events: none;"></div>

    <div class="container py-5 position-relative z-1">
        <div class="text-center mb-5 pb-4">
            <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Evolução</span>
            <h2 class="display-6 fw-bold text-dark mb-3">Nossa Trajetória</h2>
            <p class="text-muted mx-auto" style="max-width: 600px; font-size: 1.1rem;">Mais de uma década de evolução constante e compromisso com o mercado de capitais.</p>
        </div>

        <div class="row justify-content-center">
            <div class="col-lg-10 col-xl-8">
                <div class="position-relative py-4">
                    <!-- Vertical Line (Timeline Backbone) -->
                    <div class="position-absolute start-50 translate-middle-x h-100 d-none d-md-block" style="width: 2px; background: linear-gradient(to bottom, transparent, rgba(0,32,91,0.15) 10%, rgba(212,175,55,0.3) 90%, transparent); left: 50%;"></div>
                    <!-- Mobile Line -->
                    <div class="position-absolute start-0 h-100 d-md-none ms-3" style="width: 2px; background: linear-gradient(to bottom, transparent, rgba(0,32,91,0.15) 10%, rgba(212,175,55,0.3) 90%, transparent); left: 12px;"></div>

                    <!-- 2009 -->
                    <div class="row align-items-center mb-5 position-relative hover-scale-timeline" style="transition: all 0.4s ease;">
                        <div class="col-10 col-md-5 order-2 order-md-1 text-md-end pe-md-5 ps-5 ps-md-3">
                            <div class="card border-0 shadow-sm p-4 h-100 timeline-card" style="border-radius: 16px; transition: all 0.3s ease;">
                                <div class="h3 fw-bold text-brand mb-2" style="font-family: var(--font-heading);">2009</div>
                                <h5 class="fw-bold text-dark mb-2">Fundação</h5>
                                <p class="text-muted small mb-0">Início das atividades com foco em estruturação de recebíveis imobiliários.</p>
                            </div>
                        </div>
                        <div class="col-2 col-md-2 order-1 order-md-2 text-center position-absolute top-50 translate-middle-y d-flex justify-content-center d-md-none" style="left: 0; z-index: 2; width: 48px;">
                            <div class="rounded-circle bg-brand d-inline-flex align-items-center justify-content-center shadow-sm" style="width: 24px; height: 24px; border: 4px solid #fff;">
                                <div class="rounded-circle bg-white" style="width: 6px; height: 6px;"></div>
                            </div>
                        </div>
                        <div class="d-none d-md-flex col-md-2 order-1 order-md-2 text-center position-relative align-items-center justify-content-center">
                            <div class="rounded-circle bg-brand d-inline-flex align-items-center justify-content-center shadow-sm timeline-dot" style="width: 40px; height: 40px; border: 6px solid #fff; z-index: 2; transition: all 0.3s ease;">
                                <div class="rounded-circle bg-white" style="width: 10px; height: 10px;"></div>
                            </div>
                        </div>
                        <div class="col-md-5 order-3 d-none d-md-block ps-md-5"></div>
                    </div>

                    <!-- 2014 -->
                    <div class="row align-items-center mb-5 position-relative hover-scale-timeline" style="transition: all 0.4s ease;">
                        <div class="col-md-5 order-md-1 d-none d-md-block pe-md-5"></div>
                        <div class="col-2 col-md-2 order-1 order-md-2 text-center position-absolute top-50 translate-middle-y d-flex justify-content-center d-md-none" style="left: 0; z-index: 2; width: 48px;">
                            <div class="rounded-circle bg-brand d-inline-flex align-items-center justify-content-center shadow-sm" style="width: 24px; height: 24px; border: 4px solid #fff;">
                                <div class="rounded-circle bg-white" style="width: 6px; height: 6px;"></div>
                            </div>
                        </div>
                        <div class="d-none d-md-flex col-md-2 order-2 order-md-2 text-center position-relative align-items-center justify-content-center">
                            <div class="rounded-circle bg-brand d-inline-flex align-items-center justify-content-center shadow-sm timeline-dot" style="width: 40px; height: 40px; border: 6px solid #fff; z-index: 2; transition: all 0.3s ease;">
                                <div class="rounded-circle bg-white" style="width: 10px; height: 10px;"></div>
                            </div>
                        </div>
                        <div class="col-10 col-md-5 order-2 order-md-3 ps-5 ps-md-5">
                            <div class="card border-0 shadow-sm p-4 h-100 timeline-card" style="border-radius: 16px; transition: all 0.3s ease;">
                                <div class="h3 fw-bold text-brand mb-2" style="font-family: var(--font-heading);">2014</div>
                                <h5 class="fw-bold text-dark mb-2">Registro CVM</h5>
                                <p class="text-muted small mb-0">Consolidação como Companhia Aberta Categoria B, ampliando a transparência e governança.</p>
                            </div>
                        </div>
                    </div>

                    <!-- 2018 -->
                    <div class="row align-items-center mb-5 position-relative hover-scale-timeline" style="transition: all 0.4s ease;">
                        <div class="col-10 col-md-5 order-2 order-md-1 text-md-end pe-md-5 ps-5 ps-md-3">
                            <div class="card border-0 shadow-sm p-4 h-100 timeline-card" style="border-radius: 16px; transition: all 0.3s ease;">
                                <div class="h3 fw-bold text-brand mb-2" style="font-family: var(--font-heading);">2018</div>
                                <h5 class="fw-bold text-dark mb-2">Expansão para o Agro</h5>
                                <p class="text-muted small mb-0">Primeiras emissões de CRA, diversificando o portfólio para o setor de agronegócio.</p>
                            </div>
                        </div>
                        <div class="col-2 col-md-2 order-1 order-md-2 text-center position-absolute top-50 translate-middle-y d-flex justify-content-center d-md-none" style="left: 0; z-index: 2; width: 48px;">
                            <div class="rounded-circle bg-brand d-inline-flex align-items-center justify-content-center shadow-sm" style="width: 24px; height: 24px; border: 4px solid #fff;">
                                <div class="rounded-circle bg-white" style="width: 6px; height: 6px;"></div>
                            </div>
                        </div>
                        <div class="d-none d-md-flex col-md-2 order-1 order-md-2 text-center position-relative align-items-center justify-content-center">
                            <div class="rounded-circle bg-brand d-inline-flex align-items-center justify-content-center shadow-sm timeline-dot" style="width: 40px; height: 40px; border: 6px solid #fff; z-index: 2; transition: all 0.3s ease;">
                                <div class="rounded-circle bg-white" style="width: 10px; height: 10px;"></div>
                            </div>
                        </div>
                        <div class="col-md-5 order-3 d-none d-md-block ps-md-5"></div>
                    </div>

                    <!-- Hoje -->
                    <div class="row align-items-center mb-0 position-relative hover-scale-timeline" style="transition: all 0.4s ease;">
                        <div class="col-md-5 order-md-1 d-none d-md-block pe-md-5"></div>
                        <div class="col-2 col-md-2 order-1 order-md-2 text-center position-absolute top-50 translate-middle-y d-flex justify-content-center d-md-none" style="left: 0; z-index: 2; width: 48px;">
                            <div class="rounded-circle bg-gold d-inline-flex align-items-center justify-content-center shadow-sm" style="width: 28px; height: 28px; border: 4px solid #fff;">
                                <div class="rounded-circle bg-white" style="width: 8px; height: 8px;"></div>
                            </div>
                        </div>
                        <div class="d-none d-md-flex col-md-2 order-2 order-md-2 text-center position-relative align-items-center justify-content-center">
                            <div class="rounded-circle bg-gold d-inline-flex align-items-center justify-content-center shadow-sm timeline-dot" style="width: 48px; height: 48px; border: 6px solid #fff; z-index: 2; transition: all 0.3s ease; box-shadow: 0 0 15px rgba(212,175,55,0.3) !important;">
                                <div class="rounded-circle bg-white" style="width: 12px; height: 12px;"></div>
                            </div>
                        </div>
                        <div class="col-10 col-md-5 order-2 order-md-3 ps-5 ps-md-5">
                            <div class="card border-0 shadow-sm p-4 h-100 timeline-card" style="border-radius: 16px; background: linear-gradient(145deg, #ffffff, #fcfaf5); border: 1px solid rgba(212,175,55,0.15) !important; transition: all 0.3s ease;">
                                <div class="h3 fw-bold text-gold mb-2" style="font-family: var(--font-heading);">Hoje</div>
                                <h5 class="fw-bold text-dark mb-2">+R$ 1 Bilhão</h5>
                                <p class="text-muted small mb-0">Marco histórico em volume estruturado e implementação de infraestrutura digital proprietária.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Missão, Visão, Valores -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <h2 class="h3 fw-bold text-dark mb-3">Missão, visão e valores</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Nossa atuação é baseada em princípios claros que garantem parcerias duradouras e resultados reais.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 60px; height: 60px; background: rgba(0,32,91,0.08); color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="6"></circle><circle cx="12" cy="12" r="2"></circle></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Nossa Missão</h3>
                    <p class="text-muted mb-0">Criar caminhos seguros para que empresas acessem recursos no mercado de capitais, gerando valor para quem investe e combustível para quem produz.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 60px; height: 60px; background: rgba(0,32,91,0.08); color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Nossa Visão</h3>
                    <p class="text-muted mb-0">Ser a primeira escolha em securitização, reconhecidos pela agilidade técnica e pela confiança que transmitimos em cada operação estruturada.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 60px; height: 60px; background: rgba(0,32,91,0.08); color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Nossos Valores</h3>
                    <p class="text-muted mb-0">Transparência no dia a dia, tecnologia que simplifica processos e uma governança rigorosa para proteger o patrimônio de todos os parceiros.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Cultura: Ética, Inovação, Foco no Cliente -->
<section class="py-5" style="background: #0b1220;">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <h2 class="h3 fw-bold mb-3" style="color: #ffffff;">Nosso jeito de trabalhar</h2>
            <p class="mx-auto" style="max-width: 600px; color: #E6E4E4;">Acreditamos que a confiança é construída com ética, inovação e foco total no que realmente importa: o sucesso da sua operação.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card h-100 p-4 border-0" style="background: rgba(255,255,255,0.04); border-radius: 16px;">
                    <div class="mb-3">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="1.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    </div>
                    <h4 class="fw-bold mb-2" style="color: #fff; font-size: 1.1rem;">Integridade e Ética</h4>
                    <p class="mb-0" style="color: #8892b0; font-size: 0.95rem;">Trabalhamos com transparência absoluta e respeito total às normas, garantindo que sua operação esteja sempre em solo seguro.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card h-100 p-4 border-0" style="background: rgba(255,255,255,0.04); border-radius: 16px;">
                    <div class="mb-3">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="1.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                    </div>
                    <h4 class="fw-bold mb-2" style="color: #fff; font-size: 1.1rem;">Inovação prática</h4>
                    <p class="mb-0" style="color: #8892b0; font-size: 0.95rem;">Usamos a tecnologia para simplificar o complexo. Menos burocracia e mais agilidade para que o crédito chegue onde precisa.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card h-100 p-4 border-0" style="background: rgba(255,255,255,0.04); border-radius: 16px;">
                    <div class="mb-3">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="1.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                    </div>
                    <h4 class="fw-bold mb-2" style="color: #fff; font-size: 1.1rem;">Foco na solução</h4>
                    <p class="mb-0" style="color: #8892b0; font-size: 0.95rem;">Cada cliente é único. Criamos estruturas personalizadas, desenhadas sob medida para o tamanho do seu desafio.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Nossos Pilares -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <h2 class="h3 fw-bold text-dark mb-3">Nossos Pilares</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Elementos fundamentais que sustentam nossa atuação com análise criteriosa, visão estratégica e disciplina operacional.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-6 col-lg-3">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-center" style="transition: .3s;">
                    <div class="mb-3 mx-auto d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 56px; height: 56px; background: rgba(0,32,91,0.08); color: var(--brand);">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline></svg>
                    </div>
                    <h4 class="fw-bold mb-2" style="font-size: 1rem; color: #0b1220;">Planejamento Estratégico</h4>
                    <p class="text-muted mb-0" style="font-size: 0.9rem;">Definição de objetivos com visão de longo prazo para a estruturação e o acompanhamento preciso das operações.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-center" style="transition: .3s;">
                    <div class="mb-3 mx-auto d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 56px; height: 56px; background: rgba(0,32,91,0.08); color: var(--brand);">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    </div>
                    <h4 class="fw-bold mb-2" style="font-size: 1rem; color: #0b1220;">Estudo de Viabilidade</h4>
                    <p class="text-muted mb-0" style="font-size: 0.9rem;">Avaliação técnica rigorosa antes da modelagem e da entrada da operação no mercado de capitais.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-center" style="transition: .3s;">
                    <div class="mb-3 mx-auto d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 56px; height: 56px; background: rgba(0,32,91,0.08); color: var(--brand);">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                    </div>
                    <h4 class="fw-bold mb-2" style="font-size: 1rem; color: #0b1220;">Monitoramento de Mercado</h4>
                    <p class="text-muted mb-0" style="font-size: 0.9rem;">Análise contínua dos ciclos setoriais para garantir ajustes estratégicos e a preservação do valor dos ativos.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-center" style="transition: .3s;">
                    <div class="mb-3 mx-auto d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 56px; height: 56px; background: rgba(0,32,91,0.08); color: var(--brand);">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
                    </div>
                    <h4 class="fw-bold mb-2" style="font-size: 1rem; color: #0b1220;">Inteligência de Risco</h4>
                    <p class="text-muted mb-0" style="font-size: 0.9rem;">Metodologia proprietária para precificação e mitigação de riscos, assegurando o equilíbrio fiduciário de cada mandato.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Tecnologia e Infraestrutura -->
<section class="py-5" style="background-color: #f8f9fa;">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 order-lg-2">
                <div class="position-relative">
                    <img src="{{ asset('images/compliance.png') }}" class="img-fluid rounded-4 shadow-lg" alt="Tecnologia BSI" style="filter: grayscale(20%); width: 100%; height: 400px; object-fit: cover;">
                </div>
            </div>
            <div class="col-lg-6 order-lg-1">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--brand); color: var(--brand); background: rgba(0,32,91,0.05); letter-spacing: 0.1em; font-weight: 600;">Fintech-as-a-Service</span>
                <h2 class="h3 fw-bold text-dark mb-4">Infraestrutura Tecnológica e Escalabilidade</h2>
                <p class="text-muted mb-4">O mercado de capitais exige agilidade sem abrir mão da segurança. Na BSI Capital, integramos esteiras digitais e APIs proprietárias para automatizar a originação, estruturação e liquidação de operações.</p>
                <ul class="list-unstyled mb-0">
                    <li class="d-flex align-items-start mb-3">
                        <div class="me-3 text-brand">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        </div>
                        <div>
                            <strong class="d-block text-dark">Esteiras Digitais de Crédito</strong>
                            <span class="text-muted small">Automação no fluxo de aprovação e formalização de garantias.</span>
                        </div>
                    </li>
                    <li class="d-flex align-items-start mb-3">
                        <div class="me-3 text-brand">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        </div>
                        <div>
                            <strong class="d-block text-dark">Integração via APIs</strong>
                            <span class="text-muted small">Conexão fluida com originadores e plataformas de gestão financeira.</span>
                        </div>
                    </li>
                    <li class="d-flex align-items-start">
                        <div class="me-3 text-brand">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        </div>
                        <div>
                            <strong class="d-block text-dark">Monitoramento em Tempo Real</strong>
                            <span class="text-muted small">Dashboards interativos para acompanhamento de lastros e covenants.</span>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</section>

<!-- ESG e Sustentabilidade -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid #2e7d32; color: #2e7d32; background: rgba(46,125,50,0.05); letter-spacing: 0.1em; font-weight: 600;">Sustentabilidade</span>
                <h2 class="h3 fw-bold text-dark mb-4">Compromisso ESG na Originação</h2>
                <p class="text-muted mb-4">Acreditamos que o crédito estruturado é um vetor de transformação socioambiental. Integramos critérios ESG (Ambiental, Social e Governança) em nosso processo de análise, priorizando operações que geram impacto positivo e mitigam riscos de longo prazo.</p>
                <div class="d-flex flex-wrap gap-4">
                    <div class="text-center">
                        <div class="rounded-circle d-flex align-items-center justify-content-center mb-2 mx-auto" style="width: 50px; height: 50px; background: rgba(46,125,50,0.1); color: #2e7d32;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                        </div>
                        <span class="small fw-bold text-dark">Títulos Verdes</span>
                    </div>
                    <div class="text-center">
                        <div class="rounded-circle d-flex align-items-center justify-content-center mb-2 mx-auto" style="width: 50px; height: 50px; background: rgba(46,125,50,0.1); color: #2e7d32;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
                        </div>
                        <span class="small fw-bold text-dark">Impacto Social</span>
                    </div>
                    <div class="text-center">
                        <div class="rounded-circle d-flex align-items-center justify-content-center mb-2 mx-auto" style="width: 50px; height: 50px; background: rgba(46,125,50,0.1); color: #2e7d32;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="9" y1="21" x2="9" y2="9"></line></svg>
                        </div>
                        <span class="small fw-bold text-dark">Governança</span>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm h-100" style="border-radius: 16px; overflow: hidden;">
                    <div class="card-body p-5 bg-white text-center d-flex flex-column justify-content-center align-items-center">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#2e7d32" stroke-width="1.5" class="mb-4"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
                        <h4 class="fw-bold mb-3 text-dark">Conformidade e Transparência</h4>
                        <p class="text-muted mb-0">Nossas operações são estruturadas com foco em diligência contínua, assegurando que os ativos lastreados sigam rigorosamente as premissas socioambientais acordadas com os investidores.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Nossa Liderança -->
<section class="py-5" style="background-color: #f8f9fa;">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <h2 class="h3 fw-bold text-dark mb-3">Liderança Executiva</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Nossos líderes combinam décadas de experiência no mercado financeiro com uma visão clara de futuro e inovação.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <!-- Líder 1 -->
            <div class="col-md-6 col-lg-4">
                <div class="card border-0 shadow-sm text-center h-100 p-4" style="border-radius: 16px;">
                    <div class="mb-4 mx-auto position-relative" style="width: 120px; height: 120px;">
                        <div class="rounded-circle overflow-hidden bg-light d-flex align-items-center justify-content-center" style="width: 120px; height: 120px; border: 3px solid var(--brand);">
                            <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                        </div>
                        <a href="https://linkedin.com" target="_blank" class="position-absolute bottom-0 end-0 bg-white rounded-circle p-2 shadow-sm border d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;" title="LinkedIn">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="#0077b5"><path d="M19 0h-14c-2.761 0-5 2.239-5 5v14c0 2.761 2.239 5 5 5h14c2.761 0 5-2.239 5-5v-14c0-2.761-2.239-5-5-5zm-11 19h-3v-11h3v11zm-1.5-12.268c-.966 0-1.75-.79-1.75-1.764s.784-1.764 1.75-1.764 1.75.79 1.75 1.764-.783 1.764-1.75 1.764zm13.5 12.268h-3v-5.604c0-3.368-4-3.113-4 0v5.604h-3v-11h3v1.765c1.396-2.586 7-2.777 7 2.476v6.759z"/></svg>
                        </a>
                    </div>
                    <h4 class="fw-bold mb-1 text-dark" style="font-size: 1.1rem;">Diretoria Executiva</h4>
                    <p class="text-brand small fw-bold text-uppercase mb-3">Sócios-Diretores</p>
                    <p class="text-muted small mb-0">Especialistas com vasta trajetória na estruturação de ativos imobiliários, agronegócio e crédito corporativo estruturado.</p>
                </div>
            </div>
            
            <!-- Líder 2 -->
            <div class="col-md-6 col-lg-4">
                <div class="card border-0 shadow-sm text-center h-100 p-4" style="border-radius: 16px;">
                    <div class="mb-4 mx-auto position-relative" style="width: 120px; height: 120px;">
                        <div class="rounded-circle overflow-hidden bg-light d-flex align-items-center justify-content-center" style="width: 120px; height: 120px; border: 3px solid var(--brand);">
                            <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                        </div>
                        <a href="https://linkedin.com" target="_blank" class="position-absolute bottom-0 end-0 bg-white rounded-circle p-2 shadow-sm border d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;" title="LinkedIn">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="#0077b5"><path d="M19 0h-14c-2.761 0-5 2.239-5 5v14c0 2.761 2.239 5 5 5h14c2.761 0 5-2.239 5-5v-14c0-2.761-2.239-5-5-5zm-11 19h-3v-11h3v11zm-1.5-12.268c-.966 0-1.75-.79-1.75-1.764s.784-1.764 1.75-1.764 1.75.79 1.75 1.764-.783 1.764-1.75 1.764zm13.5 12.268h-3v-5.604c0-3.368-4-3.113-4 0v5.604h-3v-11h3v1.765c1.396-2.586 7-2.777 7 2.476v6.759z"/></svg>
                        </a>
                    </div>
                    <h4 class="fw-bold mb-1 text-dark" style="font-size: 1.1rem;">Governança e Risco</h4>
                    <p class="text-brand small fw-bold text-uppercase mb-3">Conselho de Administração</p>
                    <p class="text-muted small mb-0">Responsáveis por assegurar o rigor fiduciário e a conformidade regulatória junto à CVM e ANBIMA.</p>
                </div>
            </div>
        </div>
    </div>
</section>


<!-- Credenciais / Selos -->
<section class="py-5" style="background: linear-gradient(135deg, var(--brand-strong) 0%, var(--brand) 100%);">
    <div class="container py-5">
        <div class="text-center mb-5">
            <h2 class="h3 fw-bold mb-3" style="color: #ffffff;">Credenciais & Reconhecimentos</h2>
            <p class="mx-auto" style="max-width: 550px; color: #E6E4E4;">Nossa atuação é pautada por rigorosos padrões de supervisão e autorregulação do mercado financeiro.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <div class="col-md-5">
                <div class="card h-100 p-5 border-0 text-center" style="background: rgba(255,255,255,0.04); border-radius: 20px; border: 1px solid rgba(212,175,55,0.15) !important;">
                    <div class="mb-4">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="1.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    </div>
                    <h4 class="fw-bold mb-2" style="color: var(--gold); font-size: 1.2rem;">CVM — Comissão de Valores Mobiliários</h4>
                    <p class="mb-0" style="color: #8892b0;">Companhia aberta registrada na CVM, com atuação em total conformidade com o arcabouço regulatório vigente.</p>
                </div>
            </div>

            <div class="col-md-5">
                <div class="card h-100 p-5 border-0 text-center" style="background: rgba(255,255,255,0.04); border-radius: 20px; border: 1px solid rgba(212,175,55,0.15) !important;">
                    <div class="mb-4">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="1.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    </div>
                    <h4 class="fw-bold mb-2" style="color: var(--gold); font-size: 1.2rem;">ANBIMA — Autorregulação</h4>
                    <p class="mb-0" style="color: #8892b0;">Aderência aos referenciais de autorregulação, reforçando nosso compromisso com boas práticas e transparência de mercado.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CTA Final -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-5 text-center">
        <h2 class="h3 fw-bold text-dark mb-3">Conheça melhor a BSI Capital</h2>
        <p class="text-muted mx-auto mb-5" style="max-width: 550px;">Entre em contato com nossa equipe para entender como nossa expertise pode apoiar a estruturação da sua próxima operação.</p>
        <a href="{{ route('site.contact') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center gap-2 px-5 py-3 shadow-lg">
            Consultar Especialista
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
        </a>
    </div>
</section>

@push('head')
<style>
    @keyframes float {
        0% { transform: translateY(0px); }
        50% { transform: translateY(-10px); }
        100% { transform: translateY(0px); }
    }

    .hover-scale-timeline:hover .timeline-card {
        transform: translateY(-5px);
        box-shadow: 0 1rem 3rem rgba(0,0,0,.08) !important;
    }
    .hover-scale-timeline:hover .timeline-dot {
        transform: scale(1.15);
    }
    .timeline-card {
        position: relative;
    }
    @media (min-width: 768px) {
        .hover-scale-timeline:nth-child(odd) .timeline-card::after {
            content: '';
            position: absolute;
            right: -10px;
            top: 50%;
            transform: translateY(-50%);
            border-width: 10px 0 10px 10px;
            border-style: solid;
            border-color: transparent transparent transparent #fff;
            filter: drop-shadow(2px 0px 2px rgba(0,0,0,0.02));
        }
        .hover-scale-timeline:nth-child(even) .timeline-card::after {
            content: '';
            position: absolute;
            left: -10px;
            top: 50%;
            transform: translateY(-50%);
            border-width: 10px 10px 10px 0;
            border-style: solid;
            border-color: transparent #fff transparent transparent;
            filter: drop-shadow(-2px 0px 2px rgba(0,0,0,0.02));
        }
    }
</style>
@endpush
@endsection
