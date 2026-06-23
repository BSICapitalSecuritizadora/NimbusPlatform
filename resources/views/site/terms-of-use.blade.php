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
                    Regras aplicáveis ao acesso e uso do site, formulários, documentos públicos, canais digitais e demais funcionalidades disponibilizadas pela BSI Capital.
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
                        <p class="mb-4">Seja bem-vindo ao site da <strong>BSI Capital Securitizadora S.A.</strong>. A seguir, apresentamos as condições que regem o uso da nossa plataforma e serviços digitais associados.</p>

                        <h3 class="h5 fw-bold text-brand mt-5 mb-3">1. Escopo destes Termos</h3>
                        <p>Estes Termos regulam o acesso e uso do site institucional da BSI Capital, incluindo páginas públicas, formulários, áreas de documentos, canais de contato, envio de propostas, conteúdos institucionais, Relações com Investidores, BSI Intelligence e demais funcionalidades digitais disponíveis.</p>

                        <h3 class="h5 fw-bold text-brand mt-5 mb-3">2. Aceitação dos Termos</h3>
                        <p>Ao acessar ou utilizar o site da BSI Capital, o usuário declara estar ciente destes Termos de Uso e concorda em observá-los. Caso não concorde com qualquer disposição, recomenda-se interromper o uso do site e de suas funcionalidades.</p>

                        <h3 class="h5 fw-bold text-brand mt-5 mb-3">3. Envio de propostas e oportunidades</h3>
                        <p>O envio de informações, propostas ou oportunidades por meio do site não representa aprovação, aceite, mandato, compromisso de estruturação, captação, distribuição ou continuidade da operação. A análise dependerá de enquadramento preliminar, documentação, validações técnicas, jurídicas, regulatórias, comerciais e demais critérios internos da BSI Capital.</p>

                        <h3 class="h5 fw-bold text-brand mt-5 mb-3">4. Documentos públicos e informações de RI</h3>
                        <p>Documentos, comunicados, relatórios e informações disponibilizados em áreas públicas do site, incluindo Relações com Investidores e páginas de emissões, são publicados para consulta conforme disponibilidade, categoria e registros internos. A BSI Capital poderá atualizar, substituir, corrigir ou remover documentos quando necessário para preservar a consistência das informações, cumprir obrigações legais ou corrigir inconsistências operacionais.</p>

                        <h3 class="h5 fw-bold text-brand mt-5 mb-3">5. Conteúdo institucional e BSI Intelligence</h3>
                        <p>Conteúdos, artigos, relatórios ou materiais publicados pela BSI Intelligence têm finalidade informativa e institucional, podendo refletir leituras de mercado, dados públicos ou interpretações gerais. Esses conteúdos não constituem recomendação de investimento, relatório de análise regulado, oferta pública ou aconselhamento individualizado, salvo indicação expressa em sentido diverso e validação regulatória aplicável.</p>

                        <h3 class="h5 fw-bold text-brand mt-5 mb-3">6. Isenção de Responsabilidade de Investimento</h3>
                        <p>As informações disponibilizadas no site têm finalidade exclusivamente informativa e institucional. Nenhum conteúdo deve ser interpretado como oferta pública, recomendação de investimento, consultoria de valores mobiliários, análise individualizada, promessa de rentabilidade, liquidez ou desempenho futuro. Decisões de investimento devem considerar os documentos oficiais da operação, o perfil do investidor, os riscos envolvidos e a orientação de assessores especializados, quando aplicável.</p>

                        <h3 class="h5 fw-bold text-brand mt-5 mb-3">7. Uso Permitido</h3>
                        <p>O usuário compromete-se a utilizar o site de forma lícita e responsável, sendo expressamente proibido:</p>
                        <ul class="mb-4">
                            <li>Violar leis locais, nacionais ou internacionais;</li>
                            <li>Acessar áreas restritas sem autorização;</li>
                            <li>Tentar burlar autenticação, magic links, permissões ou controles de acesso;</li>
                            <li>Usar robôs, scraping abusivo ou coleta automatizada não autorizada;</li>
                            <li>Interferir no funcionamento do site ou enviar arquivos maliciosos;</li>
                            <li>Usar informações do site para fins ilícitos;</li>
                            <li>Reproduzir documentos ou conteúdos fora dos limites permitidos;</li>
                            <li>Tentar obter dados de terceiros ou documentos restritos.</li>
                        </ul>

                        <h3 class="h5 fw-bold text-brand mt-5 mb-3">8. Propriedade Intelectual</h3>
                        <p>Marcas, logotipos, nomes comerciais, layout, textos, imagens, bases de dados, relatórios, documentos e demais conteúdos do site pertencem à BSI Capital ou a terceiros licenciantes, quando aplicável, sendo protegidos pelas leis de propriedade intelectual.</p>
                        <p>O acesso ao site não confere licença, cessão ou autorização para exploração comercial do conteúdo. É permitida apenas a utilização pessoal e informativa dos materiais públicos.</p>

                        <h3 class="h5 fw-bold text-brand mt-5 mb-3">9. Privacidade e proteção de dados</h3>
                        <p>O tratamento de dados pessoais realizado por meio do site, formulários e canais digitais da BSI Capital observará a <a href="{{ route('site.privacy-policy') }}" class="text-brand fw-bold">Política de Privacidade</a> disponível no site, além da legislação aplicável. Ao utilizar funcionalidades que envolvam envio de dados pessoais, o usuário deverá fornecer informações verdadeiras, atualizadas e compatíveis com a finalidade indicada.</p>

                        <h3 class="h5 fw-bold text-brand mt-5 mb-3">10. Segurança e credenciais de acesso</h3>
                        <p>O usuário é responsável por preservar a confidencialidade de links, credenciais, códigos de acesso ou comunicações recebidas para utilização de funcionalidades digitais da BSI Capital. É proibido compartilhar acessos restritos, tentar acessar informações de terceiros ou utilizar mecanismos de autenticação de forma indevida.</p>

                        <h3 class="h5 fw-bold text-brand mt-5 mb-3">11. Links para Terceiros</h3>
                        <p>O site pode conter links para páginas de terceiros, incluindo redes sociais, órgãos reguladores, parceiros tecnológicos ou provedores externos. A BSI Capital não controla esses ambientes e não se responsabiliza por seus conteúdos, práticas de privacidade, disponibilidade ou políticas próprias.</p>

                        <h3 class="h5 fw-bold text-brand mt-5 mb-3">12. Limitação de Responsabilidade</h3>
                        <p>A BSI Capital adota esforços razoáveis para manter as informações do site atualizadas e disponíveis, mas não garante disponibilidade ininterrupta, ausência de erros, falhas técnicas ou desatualizações pontuais. Na máxima extensão permitida pela legislação aplicável, a BSI Capital não será responsável por danos decorrentes do uso indevido do site, indisponibilidades temporárias, interpretação inadequada das informações ou atos de terceiros.</p>

                        <h3 class="h5 fw-bold text-brand mt-5 mb-3">13. Atualizações dos Termos</h3>
                        <p>A BSI Capital poderá atualizar estes Termos de Uso a qualquer momento para refletir alterações legais, regulatórias, operacionais ou tecnológicas. A versão vigente será sempre aquela publicada nesta página, com indicação da data de última atualização.</p>

                        <h3 class="h5 fw-bold text-brand mt-5 mb-3">14. Foro e Legislação</h3>
                        <p>Estes Termos de Uso são regidos pelas leis da República Federativa do Brasil. Para a resolução de quaisquer conflitos decorrentes deste documento, fica eleito o Foro da Comarca de São Paulo/SP, com exclusão de qualquer outro, observadas as regras de competência aplicáveis pela legislação vigente.</p>

                        <hr class="my-5 border-brand-subtle">
                        <p class="small text-muted text-end">Última atualização: Junho de 2026.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection
