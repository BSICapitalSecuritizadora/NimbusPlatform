@extends('site.layout')
@section('title', 'BSI Intelligence - Relatórios e Análises de Mercado')

@section('content')
<section class="hero-small position-relative overflow-hidden" style="background: linear-gradient(135deg, #020918 0%, #051a3d 100%); padding: 100px 0 60px;">
    <div class="container position-relative z-1">
        <div class="row">
            <div class="col-lg-8">
                <div class="kicker text-gold mb-3">Conteúdo Estratégico</div>
                <h1 class="display-4 fw-bold text-white mb-4">BSI Intelligence</h1>
                <p class="lead text-white-50 mb-0">
                    Análises, relatórios e visões exclusivas sobre o mercado de securitização, crédito estruturado e agronegócio.
                </p>
            </div>
        </div>
    </div>
</section>

<section class="py-5">
    <div class="container py-4">
        <div class="row g-4 mb-5">
            <div class="col-md-6 col-lg-3">
                <div class="card p-3 border-0 shadow-sm text-center">
                    <div class="fw-bold h4 mb-1">CRI</div>
                    <div class="small text-muted">Imobiliário</div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="card p-3 border-0 shadow-sm text-center">
                    <div class="fw-bold h4 mb-1">CRA</div>
                    <div class="small text-muted">Agronegócio</div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="card p-3 border-0 shadow-sm text-center">
                    <div class="fw-bold h4 mb-1">CR</div>
                    <div class="small text-muted">Corporativo</div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="card p-3 border-0 shadow-sm text-center">
                    <div class="fw-bold h4 mb-1">Regulatório</div>
                    <div class="small text-muted">CVM & Normas</div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            {{-- Relatório Placeholder 1 --}}
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm overflow-hidden h-100">
                    <div class="row g-0 h-100">
                        <div class="col-sm-4">
                            <img src="https://images.unsplash.com/photo-1551288049-bbbda536339a?q=80&w=400&auto=format&fit=crop" class="w-100 h-100 object-fit-cover" alt="Relatório">
                        </div>
                        <div class="col-sm-8">
                            <div class="p-4">
                                <div class="badge badge-soft-brand mb-2">Trimestral</div>
                                <h3 class="h5 fw-bold mb-2">Panorama BSI: Mercado de CRI e Perspectivas 2026</h3>
                                <p class="small text-muted mb-4">Análise detalhada sobre o volume de emissões, taxas médias e o impacto das novas normas CVM no setor imobiliário.</p>
                                <a href="#" class="btn btn-outline-brand btn-sm">Download do Relatório</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Relatório Placeholder 2 --}}
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm overflow-hidden h-100">
                    <div class="row g-0 h-100">
                        <div class="col-sm-4">
                            <img src="https://images.unsplash.com/photo-1495055154266-57bbdeada43e?q=80&w=400&auto=format&fit=crop" class="w-100 h-100 object-fit-cover" alt="Relatório">
                        </div>
                        <div class="col-sm-8">
                            <div class="p-4">
                                <div class="badge badge-soft-brand mb-2">Especial</div>
                                <h3 class="h5 fw-bold mb-2">Crédito para o Agronegócio: A força do CRA</h3>
                                <p class="small text-muted mb-4">Como as estruturas de securitização agrícola estão viabilizando o crescimento de cooperativas e produtores rurais.</p>
                                <a href="#" class="btn btn-outline-brand btn-sm">Download do Relatório</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Newsletter Signup --}}
        <div class="mt-5 p-4 p-lg-5 text-center bg-light rounded-4">
            <h3 class="h4 fw-bold mb-3">Receba nossas análises em seu e-mail</h3>
            <p class="text-muted mb-4 mx-auto" style="max-width: 500px;">Assine a BSI Intelligence para ser notificado sobre novos relatórios e insights do mercado.</p>
            <form class="d-flex flex-column flex-sm-row gap-2 justify-content-center mx-auto" style="max-width: 500px;">
                <input type="email" class="form-control" placeholder="Seu melhor e-mail" required>
                <button type="submit" class="btn btn-brand px-4">Assinar</button>
            </form>
        </div>
    </div>
</section>
@endsection
