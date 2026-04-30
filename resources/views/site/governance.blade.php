@extends('site.layout')
@section('title','Governança Corporativa — BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 55vh; overflow: hidden; background: #001233;">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.08; background: url('{{ asset('images/estrutura_juridica.png') }}') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-7">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Institucional</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Governança <br><span style="color: var(--gold);">Corporativa</span>
                </h1>
                <p class="lead mb-0" style="color: #a5b4fc; max-width: 90%;">
                    Nossa estrutura de governança integra instâncias decisórias, controles internos rigorosos e disciplina regulatória, assegurando a integridade institucional e a perenidade das operações estruturadas.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- Programa de Compliance -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--brand); color: var(--brand); background: rgba(0,32,91,0.05); letter-spacing: 0.1em; font-weight: 600;">Compliance</span>
                <h2 class="h3 fw-bold text-dark mb-4">Programa de Compliance</h2>
                <p class="text-muted mb-3">A BSI Capital mantém um Programa de Compliance robusto, pautado por revisões periódicas que asseguram a aderência contínua às melhores práticas do mercado financeiro e de capitais.</p>
                <p class="text-muted mb-4">Essa estrutura fundamenta a definição de políticas e procedimentos que resguardam a integridade dos fluxos internos, mitigando riscos operacionais, reputacionais e de conformidade com transparência e rigor analítico.</p>

                <div class="d-flex flex-column gap-3">
                    <div class="d-flex align-items-start gap-3">
                        <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-circle" style="width: 40px; height: 40px; background: rgba(0,32,91,0.08); color: var(--brand);">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        </div>
                        <div>
                            <div class="fw-bold" style="color: #0b1220; font-size: 0.95rem;">Políticas e Normativos Internos</div>
                            <div class="text-muted" style="font-size: 0.9rem;">Diretrizes aplicáveis a colaboradores e prestadores de serviços, alinhadas aos valores da companhia.</div>
                        </div>
                    </div>
                    <div class="d-flex align-items-start gap-3">
                        <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-circle" style="width: 40px; height: 40px; background: rgba(0,32,91,0.08); color: var(--brand);">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        </div>
                        <div>
                            <div class="fw-bold" style="color: #0b1220; font-size: 0.95rem;">Vigilância Regulatória</div>
                            <div class="text-muted" style="font-size: 0.9rem;">Acompanhamento contínuo das exigências da CVM e demais órgãos reguladores do setor.</div>
                        </div>
                    </div>
                    <div class="d-flex align-items-start gap-3">
                        <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-circle" style="width: 40px; height: 40px; background: rgba(0,32,91,0.08); color: var(--brand);">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        </div>
                        <div>
                            <div class="fw-bold" style="color: #0b1220; font-size: 0.95rem;">Gerenciamento de Riscos</div>
                            <div class="text-muted" style="font-size: 0.9rem;">Controles desenhados para mitigar a exposição a riscos operacionais e de imagem institucional.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card border-0 p-5" style="background: linear-gradient(135deg, rgba(0,32,91,0.04), rgba(212,175,55,0.04)); border-radius: 20px;">
                    <h4 class="fw-bold mb-4" style="color: var(--brand); font-size: 1.1rem;">Estrutura de Governança</h4>
                    <div class="d-flex flex-column gap-3">
                        <div class="d-flex align-items-center gap-3 p-3 rounded-3" style="background: rgba(0,32,91,0.04);">
                            <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-circle" style="width: 44px; height: 44px; background: var(--brand); color: #fff;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                            </div>
                            <div>
                                <div class="fw-bold" style="font-size: 0.95rem; color: #0b1220;">Diretoria Executiva</div>
                                <div class="text-muted" style="font-size: 0.85rem;">Gestão estratégica e supervisão integral das diretrizes operacionais da companhia.</div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-3 p-3 rounded-3" style="background: rgba(0,32,91,0.04);">
                            <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-circle" style="width: 44px; height: 44px; background: var(--brand); color: #fff;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                            </div>
                            <div>
                                <div class="fw-bold" style="font-size: 0.95rem; color: #0b1220;">Comitê de Compliance</div>
                                <div class="text-muted" style="font-size: 0.85rem;">Zelo pelas diretrizes de conformidade, conduta ética e eficiência dos controles internos.</div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-3 p-3 rounded-3" style="background: rgba(0,32,91,0.04);">
                            <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-circle" style="width: 44px; height: 44px; background: var(--brand); color: #fff;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
                            </div>
                            <div>
                                <div class="fw-bold" style="font-size: 0.95rem; color: #0b1220;">Comitê de Riscos</div>
                                <div class="text-muted" style="font-size: 0.85rem;">Monitoramento fiduciário e mitigação de riscos associados às operações de securitização.</div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-3 p-3 rounded-3" style="background: rgba(0,32,91,0.04);">
                            <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-circle" style="width: 44px; height: 44px; background: var(--brand); color: #fff;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                            </div>
                            <div>
                                <div class="fw-bold" style="font-size: 0.95rem; color: #0b1220;">Auditoria Interna</div>
                                <div class="text-muted" style="font-size: 0.85rem;">Avaliação independente com estrita segregação de funções, visando a eficácia do ambiente de controle.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Manuais Regulatórios -->
<section class="py-5" style="background: #0b1220;">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <h2 class="h3 fw-bold mb-3" style="color: #ffffff;">Manuais & Políticas Regulatórias</h2>
            <p class="mx-auto" style="max-width: 600px; color: #a5b4fc;">Referenciais Normativos: Documentos que consolidam nossa cultura fiduciária e estabelecem padrões éticos e operacionais em prol da segurança dos investidores.</p>
        </div>

        <div class="row g-4">
            @forelse($documents as $document)
            <div class="col-md-6 col-lg-4">
                <a href="{{ Storage::disk($document->resolved_storage_disk)->url($document->file_path) }}" target="_blank" class="text-decoration-none" download>
                    <div class="card h-100 p-4 border-0" style="background: rgba(255,255,255,0.04); border-radius: 16px; border: 1px solid rgba(255,255,255,0.06) !important; transition: .3s;">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                            <h4 class="fw-bold mb-0" style="color: #fff; font-size: 1rem;">{{ $document->title }}</h4>
                        </div>
                        <div class="d-flex align-items-center justify-content-between">
                            <span style="color: #8892b0; font-size: 0.85rem;">
                                {{ $document->published_at?->format('d/m/Y') ?? $document->created_at->format('d/m/Y') }}
                                @if($document->file_size)
                                    · {{ number_format($document->file_size / 1024, 0) }} KB
                                @endif
                            </span>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        </div>
                    </div>
                </a>
            </div>
            @empty
            <div class="col-12 text-center py-4">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.2)" stroke-width="1.5" class="mb-3"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                <p class="mb-0" style="color: #8892b0;">Os documentos normativos de governança estarão disponíveis nesta seção após sua publicação oficial.</p>
            </div>
            @endforelse
        </div>

    </div>
</section>

<!-- Gestão de Riscos -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <h2 class="h3 fw-bold text-dark mb-3">Gestão de Riscos</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Abordagem estruturada para identificar, avaliar, monitorar e mitigar os riscos inerentes às operações de securitização.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 60px; height: 60px; background: rgba(0,32,91,0.08); color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Risco de Crédito</h3>
                    <p class="text-muted mb-0">Avaliação rigorosa do lastro e formalização de garantias, com análise de qualidade dos recebíveis para assegurar a blindagem patrimonial da estrutura.</p>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 60px; height: 60px; background: rgba(0,32,91,0.08); color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Risco de Mercado</h3>
                    <p class="text-muted mb-0">Monitoramento de variáveis econômicas, taxas e indexadores capazes de influenciar o comportamento das operações ao longo do tempo.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 60px; height: 60px; background: rgba(0,32,91,0.08); color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Risco Operacional</h3>
                    <p class="text-muted mb-0">Segregação de funções e trilhas de auditoria imutáveis, com controles internos desenhados para eliminar falhas de processo e garantir a integridade dos dados.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Nossos Inegociáveis -->
<section class="py-5" style="background: #ffffff;">
    <div class="container py-4">
        <div class="row justify-content-center text-center">
            <div class="col-lg-10">
                <div class="p-5 rounded-4 border shadow-sm" style="background: linear-gradient(to right, rgba(212,175,55,0.02), rgba(0,32,91,0.02));">
                    <h2 class="h4 fw-bold mb-4" style="color: var(--brand);">Princípios Inegociáveis</h2>
                    <div class="row g-4">
                        <div class="col-md-4">
                            <div class="fw-bold mb-2" style="color: var(--gold);">Transparência Radical</div>
                            <p class="small text-muted mb-0">Comunicação aberta e precisa com todos os stakeholders sobre o desempenho e os riscos das operações.</p>
                        </div>
                        <div class="col-md-4">
                            <div class="fw-bold mb-2" style="color: var(--gold);">Equidade de Tratamento</div>
                            <p class="small text-muted mb-0">Garantia de isonomia e respeito aos direitos de todos os investidores, independentemente do porte ou perfil.</p>
                        </div>
                        <div class="col-md-4">
                            <div class="fw-bold mb-2" style="color: var(--gold);">Accountability</div>
                            <p class="small text-muted mb-0">Prestação de contas rigorosa, com responsabilidade clara sobre cada decisão e resultado alcançado.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CTA -->
<section class="py-5" style="background: linear-gradient(135deg, #001233 0%, #0b1220 100%);">
    <div class="container py-5 text-center">
        <h2 class="h3 fw-bold mb-3" style="color: #ffffff;">Estamos à Sua Disposição</h2>
        <p class="mx-auto mb-5" style="max-width: 550px; color: #a5b4fc;">Se precisar de esclarecimentos sobre nossas práticas de governança ou canais institucionais de contato, fale com nossa equipe.</p>
        <a href="{{ route('site.contact') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center gap-2 px-5 py-3 shadow-lg">
            Fale com nossa equipe
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
        </a>
    </div>
</section>
@endsection
