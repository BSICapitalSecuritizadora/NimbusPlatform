@extends('site.layout')
@section('title', 'Contato — BSI Capital')

@section('content')
<section class="hero position-relative d-flex align-items-center" style="min-height: 42vh;">
    <div class="container position-relative">
        <div class="row align-items-center g-4">
            <div class="col-lg-8">
                <span class="badge mb-3 px-3 py-2 text-uppercase">Atendimento institucional</span>
                <h1 class="display-4 fw-bold mb-3">
                    Entre em contato com a <span style="color: var(--gold);">BSI Capital</span>
                </h1>
                <p class="lead mb-0" style="max-width: 760px;">
                    Estamos à disposição para avaliar novas teses de operação ou suportar demandas institucionais. Nosso atendimento prioriza o rigor técnico e a viabilidade fiduciária exigidos pelo mercado.
                </p>
            </div>
        </div>
    </div>
</section>

<section class="py-5">
    <div class="container py-lg-5">
        <div class="row g-4 align-items-stretch mb-5">
            <div class="col-md-4">
                <div class="surface-card h-100 p-4">
                    <div class="section-kicker mb-2">Canal institucional</div>
                    <h2 class="h4 fw-bold text-brand mb-2">E-mail</h2>
                    <p class="section-copy mb-3">Utilize este canal para demandas institucionais, comerciais e operacionais.</p>
                    <a href="mailto:contato@bsicapital.com.br" class="fw-semibold text-brand text-decoration-none">contato@bsicapital.com.br</a>
                </div>
            </div>
            <div class="col-md-4">
                <div class="surface-card h-100 p-4">
                    <div class="section-kicker mb-2">Atendimento</div>
                    <h2 class="h4 fw-bold text-brand mb-2">Telefone</h2>
                    <p class="section-copy mb-3">Atendimento corporativo e suporte direto em dias úteis, das 09h às 18h.</p>
                    <a href="tel:+551123678793" class="fw-semibold text-brand text-decoration-none">+55 (11) 2367-8793</a>
                </div>
            </div>
            <div class="col-md-4">
                <div class="surface-card h-100 p-4">
                    <div class="section-kicker mb-2">Base operacional</div>
                    <h2 class="h4 fw-bold text-brand mb-2">São Paulo</h2>
                    <p class="section-copy mb-0">
                        Avenida das Nações Unidas, 14.401<br>
                        Tarumã Tower, Sala 713<br>
                        Chácara Santo Antônio, São Paulo - SP
                    </p>
                </div>
            </div>
        </div>

        <div class="row g-4 align-items-stretch">
            <div class="col-lg-5">
                <div class="surface-card h-100 p-4 p-lg-5">
                    <div class="section-kicker mb-2">Fale conosco</div>
                    <h2 class="h3 fw-bold text-brand mb-3">Atendimento claro e direcionado à área responsável</h2>
                    <p class="section-copy mb-4">
                        Sua demanda será analisada diretamente pelo time responsável. Priorizamos um retorno inicial em até 24 horas úteis, focado em clareza técnica e direcionamento jurídico.
                    </p>

                    <div class="d-flex flex-column gap-3">
                        <div class="surface-card-soft p-3">
                            <div class="small text-uppercase text-muted fw-semibold mb-1">Comercial e novos negócios</div>
                            <div class="fw-semibold">Estruturação, securitização e análise preliminar de operações</div>
                        </div>
                        <div class="surface-card-soft p-3">
                            <div class="small text-uppercase text-muted fw-semibold mb-1">Relacionamento institucional</div>
                            <div class="fw-semibold">Contato com investidores, documentos públicos e comunicações corporativas</div>
                        </div>
                        <div class="surface-card-soft p-3">
                            <div class="small text-uppercase text-muted fw-semibold mb-1">Compliance e ética</div>
                            <div class="fw-semibold">Demandas de conformidade, governança e canais sensíveis</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="surface-card h-100 p-4 p-lg-5">
                    <div class="mb-4">
                        <div class="section-kicker mb-2">Formulário</div>
                        <h2 class="h3 fw-bold text-brand mb-2">Envie sua mensagem</h2>
                        <p class="section-copy mb-0">As informações abaixo permitem um direcionamento técnico e seguro da sua demanda para a área responsável.</p>
                    </div>

                    <form action="#" method="POST" class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Nome</label>
                            <input type="text" class="form-control" placeholder="Informe seu nome completo" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">E-mail</label>
                            <input type="email" class="form-control" placeholder="Informe seu e-mail corporativo" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Telefone</label>
                            <input type="tel" class="form-control" placeholder="Informe seu telefone">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Assunto</label>
                            <select class="form-select">
                                <option selected disabled>Selecione a área de interesse</option>
                                <option>Relações com investidores</option>
                                <option>Comercial e novos negócios</option>
                                <option>Compliance e canal de ética</option>
                                <option>Carreiras / Trabalhe conosco</option>
                                <option>Assuntos institucionais</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Mensagem</label>
                            <textarea class="form-control" rows="5" placeholder="Descreva brevemente sua demanda ou tese de operação" required></textarea>
                        </div>
                        <div class="col-12 pt-2">
                            <button type="submit" class="btn btn-brand btn-lg px-5 mb-3">Iniciar Atendimento</button>
                            <p class="small text-muted mb-0" style="font-size: 0.75rem; line-height: 1.4;">
                                As informações fornecidas são protegidas por protocolos de sigilo em conformidade com a LGPD e nossa política de integridade corporativa.
                            </p>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="pb-5">
    <div class="container">
        <div class="surface-card overflow-hidden">
            <div class="row g-0">
                <div class="col-lg-4 p-4 p-lg-5">
                    <div class="section-kicker mb-2">Localização</div>
                    <h2 class="h3 fw-bold text-brand mb-3">Sede Institucional</h2>
                    <p class="section-copy mb-0">
                        Nossa base em São Paulo concentra a inteligência estratégica, operacional e fiduciária da BSI Capital.
                    </p>
                </div>
                <div class="col-lg-8">
                    <iframe
                        src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3655.4502951281343!2d-46.70595342358573!3d-23.624039663899975!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x94ce42360bb98d7f%3A0xa4ab8704821d7133!2sBSI%20Capital%20Securitizadora%20S%2FA!5e0!3m2!1spt-BR!2sbr!4v1774380432797!5m2!1spt-BR!2sbr"
                        width="100%"
                        height="100%"
                        style="border:0; min-height: 420px;"
                        allowfullscreen=""
                        loading="lazy"
                        referrerpolicy="no-referrer-when-downgrade">
                    </iframe>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection
