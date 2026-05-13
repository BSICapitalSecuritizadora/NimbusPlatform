@extends('site.layout')
@section('title','Política de Privacidade — BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 40vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.05; background: radial-gradient(circle at 50% 50%, var(--gold), transparent 70%);"></div>

    <div class="container position-relative z-1">
        <div class="row">
            <div class="col-lg-8">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Legal</span>
                <h1 class="display-4 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Política de <span style="color: var(--gold);">Privacidade</span>
                </h1>
                <p class="lead mb-0" style="color: #a5b4fc;">
                    Compromisso com a proteção de dados e transparência no tratamento de informações.
                </p>
            </div>
        </div>
    </div>
</section>

<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="card border-0 shadow-sm p-4 p-md-5" style="border-radius: var(--radius-card); background: white;">
                    <div class="prose">
                        <p class="mb-4">A <strong>BSI Capital Securitizadora S.A.</strong> valoriza a privacidade de seus usuários e clientes. Esta Política de Privacidade descreve como coletamos, usamos, armazenamos e protegemos suas informações pessoais em conformidade com a Lei Geral de Proteção de Dados (LGPD - Lei nº 13.709/2018).</p>

                        <h3 class="h5 fw-bold text-brand mt-5 mb-3">1. Coleta de Informações</h3>
                        <p>Coletamos informações que você nos fornece voluntariamente ao entrar em contato conosco, enviar propostas ou se candidatar a vagas, incluindo nome, e-mail, telefone e dados profissionais. Também podemos coletar dados técnicos de navegação (cookies) para melhorar sua experiência em nosso site.</p>

                        <h3 class="h5 fw-bold text-brand mt-5 mb-3">2. Uso dos Dados</h3>
                        <p>Os dados coletados são utilizados para:</p>
                        <ul class="mb-4">
                            <li>Responder a solicitações de contato e suporte;</li>
                            <li>Processar propostas de estruturação de operações;</li>
                            <li>Gerenciar processos seletivos;</li>
                            <li>Cumprir obrigações legais e regulatórias junto à CVM e outros órgãos;</li>
                            <li>Melhorar a segurança e a funcionalidade do nosso site.</li>
                        </ul>

                        <h3 class="h5 fw-bold text-brand mt-5 mb-3">3. Compartilhamento de Informações</h3>
                        <p>A BSI Capital não comercializa seus dados pessoais. O compartilhamento de informações pode ocorrer com:</p>
                        <ul class="mb-4">
                            <li>Órgãos reguladores e autoridades judiciais, quando exigido por lei;</li>
                            <li>Parceiros e prestadores de serviços essenciais para a execução das operações, sob rigorosos contratos de confidencialidade;</li>
                            <li>Instituições financeiras envolvidas no fluxo das operações estruturadas.</li>
                        </ul>

                        <h3 class="h5 fw-bold text-brand mt-5 mb-3">4. Segurança dos Dados</h3>
                        <p>Adotamos medidas técnicas e administrativas avançadas para proteger seus dados contra acessos não autorizados, perda, alteração ou qualquer forma de tratamento inadequado ou ilícito.</p>

                        <h3 class="h5 fw-bold text-brand mt-5 mb-3">5. Seus Direitos</h3>
                        <p>De acordo com a LGPD, você tem o direito de confirmar a existência de tratamento, acessar seus dados, solicitar a correção de dados incompletos ou inexatos, e requerer a anonimização ou exclusão de dados desnecessários.</p>

                        <h3 class="h5 fw-bold text-brand mt-5 mb-3">6. Contato</h3>
                        <p>Para exercer seus direitos ou tirar dúvidas sobre esta política, entre em contato através do e-mail: <a href="mailto:contato@bsicapital.com.br" class="text-brand fw-bold">contato@bsicapital.com.br</a>.</p>

                        <hr class="my-5">
                        <p class="small text-muted text-end">Última atualização: Abril de 2026.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection
