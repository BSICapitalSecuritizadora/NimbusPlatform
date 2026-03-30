@extends('site.layout')
@section('title','Contato — BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 40vh; overflow: hidden; background: #001233;">
    <div class="container position-relative z-1 text-center text-lg-start">
        <div class="row">
            <div class="col-lg-8">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Atendimento</span>
                <h1 class="display-4 fw-bold mb-3" style="color: #ffffff; letter-spacing: -0.02em;">
                    Entre em contato com a <span style="color: var(--gold);">BSI Capital</span>
                </h1>
                <p class="lead mb-0" style="color: #a5b4fc; max-width: 80%;">
                    Nossa equipe está disponível para atender demandas comerciais, institucionais e de relacionamento. Utilize os canais abaixo para falar conosco com segurança e agilidade.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- Contact Content -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-lg-5">
        <div class="row g-5">
            <!-- Info Column -->
            <div class="col-lg-5">
                <div class="d-flex flex-column gap-4">
                    <!-- Card Email -->
                    <div class="d-flex align-items-start gap-4 p-4 rounded-4 shadow-sm bg-white card-hover">
                        <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-circle shadow-sm" style="width: 56px; height: 56px; background: rgba(0,32,91,0.06); color: var(--gold);">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                        </div>
                        <div>
                            <h3 class="h6 fw-bold mb-1" style="color: var(--brand);">E-mail institucional</h3>
                            <a href="mailto:contato@bsicapital.com.br" class="text-muted text-decoration-none">contato@bsicapital.com.br</a>
                        </div>
                    </div>

                    <!-- Card Phone -->
                    <div class="d-flex align-items-start gap-4 p-4 rounded-4 shadow-sm bg-white card-hover">
                        <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-circle shadow-sm" style="width: 56px; height: 56px; background: rgba(0,32,91,0.06); color: var(--gold);">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                        </div>
                        <div>
                            <h3 class="h6 fw-bold mb-1" style="color: var(--brand);">Telefone</h3>
                            <a href="tel:+551123678793" class="text-muted text-decoration-none">+55 (11) 2367-8793</a>
                        </div>
                    </div>

                    <!-- Card Address -->
                    <div class="d-flex align-items-start gap-4 p-4 rounded-4 shadow-sm bg-white card-hover">
                        <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-circle shadow-sm" style="width: 56px; height: 56px; background: rgba(0,32,91,0.06); color: var(--gold);">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                        </div>
                        <div>
                            <h3 class="h6 fw-bold mb-1" style="color: var(--brand);">Endereço</h3>
                            <p class="text-muted mb-0 small">
                                Avenida das Nações Unidas, 14.401<br>
                                Tarumã Tower, Sala 713<br>
                                Chácara Santo Antônio, São Paulo - SP
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form Column -->
            <div class="col-lg-7">
                <div class="card p-4 p-md-5 border-0 shadow-lg rounded-4 overflow-hidden">
                    <div class="mb-4">
                        <h2 class="h4 fw-bold mb-2" style="color: var(--brand);">Envie sua mensagem</h2>
                        <p class="text-muted small">Preencha os dados abaixo. Seu contato será direcionado para a área responsável e retornaremos assim que possível.</p>
                    </div>
                    
                    <form action="#" method="POST" class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-muted">Nome</label>
                            <input type="text" class="form-control border-light shadow-none bg-light ps-3 py-2" placeholder="Informe seu nome" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-muted">E-mail</label>
                            <input type="email" class="form-control border-light shadow-none bg-light ps-3 py-2" placeholder="Informe seu e-mail" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-muted">Telefone</label>
                            <input type="tel" class="form-control border-light shadow-none bg-light ps-3 py-2" placeholder="Informe seu telefone">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-muted">Assunto</label>
                            <select class="form-select border-light shadow-none bg-light ps-3 py-2">
                                <option selected disabled>Selecione o assunto</option>
                                <option>Relações com investidores</option>
                                <option>Comercial e novos negócios</option>
                                <option>Compliance e canal de ética</option>
                                <option>Carreiras / Trabalhe conosco</option>
                                <option>Assuntos institucionais</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold text-muted">Mensagem</label>
                            <textarea class="form-control border-light shadow-none bg-light ps-3 py-2" rows="4" placeholder="Descreva como podemos ajudar" required></textarea>
                        </div>
                        <div class="col-12 mt-4">
                            <button type="submit" class="btn btn-brand btn-lg w-100 shadow-sm">
                                Enviar contato
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Map Section -->
<section class="vh-50 w-100 bg-light overflow-hidden">
    <iframe 
        src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3655.4502951281343!2d-46.70595342358573!3d-23.624039663899975!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x94ce42360bb98d7f%3A0xa4ab8704821d7133!2sBSI%20Capital%20Securitizadora%20S%2FA!5e0!3m2!1spt-BR!2sbr!4v1774380432797!5m2!1spt-BR!2sbr" 
        width="100%" 
        height="100%" 
        style="border:0; min-height: 450px;" 
        allowfullscreen="" 
        loading="lazy" 
        referrerpolicy="no-referrer-when-downgrade">
    </iframe>
</section>
@endsection
