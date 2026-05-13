@extends('site.layout')
@section('title','Termos de Uso — BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 40vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.05; background: radial-gradient(circle at 50% 50%, var(--gold), transparent 70%);"></div>

    <div class="container position-relative z-1">
        <div class="row">
            <div class="col-lg-8">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Legal</span>
                <h1 class="display-4 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Termos de <span style="color: var(--gold);">Uso</span>
                </h1>
                <p class="lead mb-0" style="color: #a5b4fc;">
                    Regras e diretrizes para o acesso e utilização dos serviços digitais da BSI Capital.
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
                        <p class="mb-4">Seja bem-vindo ao site da <strong>BSI Capital Securitizadora S.A.</strong>. Ao acessar ou utilizar nossa plataforma, você concorda em cumprir e estar vinculado aos seguintes Termos de Uso. Caso não concorde com qualquer parte destes termos, solicitamos que não utilize nossos serviços digitais.</p>

                        <h3 class="h5 fw-bold text-brand mt-5 mb-3">1. Aceitação dos Termos</h3>
                        <p>O acesso ao site da BSI Capital implica na aceitação plena das condições aqui estabelecidas. Estes termos podem ser atualizados periodicamente para refletir mudanças regulatórias ou melhorias em nossos serviços, sendo sua responsabilidade revisá-los regularmente.</p>

                        <h3 class="h5 fw-bold text-brand mt-5 mb-3">2. Propriedade Intelectual</h3>
                        <p>Todo o conteúdo deste site, incluindo textos, logotipos, gráficos, imagens, ícones e software, é de propriedade exclusiva da BSI Capital ou de seus licenciadores, sendo protegido pelas leis de direitos autorais e propriedade intelectual. É proibida a reprodução, distribuição ou modificação de qualquer conteúdo sem autorização prévia por escrito.</p>

                        <h3 class="h5 fw-bold text-brand mt-5 mb-3">3. Uso Permitido</h3>
                        <p>O usuário compromete-se a utilizar o site de forma lícita, sendo proibido:</p>
                        <ul class="mb-4">
                            <li>Violar leis locais, nacionais ou internacionais;</li>
                            <li>Tentar obter acesso não autorizado a nossos sistemas ou redes;</li>
                            <li>Utilizar o site para disseminar vírus ou códigos maliciosos;</li>
                            <li>Interferir na integridade ou no desempenho do site e de seus serviços.</li>
                        </ul>

                        <h3 class="h5 fw-bold text-brand mt-5 mb-3">4. Isenção de Responsabilidade de Investimento</h3>
                        <p>As informações contidas neste site têm caráter meramente informativo e institucional. <strong>O conteúdo aqui disponibilizado não constitui oferta de venda, recomendação de investimento ou consultoria financeira.</strong> Decisões baseadas nas informações deste site são de inteira responsabilidade do usuário.</p>

                        <h3 class="h5 fw-bold text-brand mt-5 mb-3">5. Links para Terceiros</h3>
                        <p>Nosso site pode conter links para sites de terceiros (como LinkedIn ou portais regulatórios). A BSI Capital não exerce controle sobre esses sites e não se responsabiliza pelo conteúdo, políticas de privacidade ou práticas de terceiros.</p>

                        <h3 class="h5 fw-bold text-brand mt-5 mb-3">6. Limitação de Responsabilidade</h3>
                        <p>A BSI Capital envida esforços para manter as informações precisas e atualizadas, mas não garante a ausência de erros ou interrupções no acesso ao site. Não seremos responsáveis por quaisquer danos diretos ou indiretos decorrentes do uso desta plataforma.</p>

                        <h3 class="h5 fw-bold text-brand mt-5 mb-3">7. Foro e Legislação</h3>
                        <p>Estes Termos de Uso são regidos pelas leis da República Federativa do Brasil. Para a resolução de quaisquer conflitos decorrentes deste documento, fica eleito o Foro da Comarca de São Paulo/SP, com exclusão de qualquer outro.</p>

                        <hr class="my-5">
                        <p class="small text-muted text-end">Última atualização: Abril de 2026.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection
