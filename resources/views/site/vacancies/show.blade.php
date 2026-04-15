@extends('site.layout')

@section('title', $vacancy->title . ' — BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center mb-5" style="min-height: 35vh; overflow: hidden; background: #001233;">
    <div class="container position-relative z-1">
        <div class="row align-items-center">
            <div class="col-lg-12">
                <nav aria-label="breadcrumb" class="mb-4">
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><a href="{{ route('site.vacancies.index') }}" class="text-white-50 text-decoration-none small">Trabalhe Conosco</a></li>
                        <li class="breadcrumb-item active text-white small" aria-current="page">{{ $vacancy->title }}</li>
                    </ol>
                </nav>
                <h1 class="display-5 fw-bold mb-3" style="color: #ffffff; letter-spacing: -0.01em;">
                    {{ $vacancy->title }}
                </h1>
                <div class="d-flex flex-wrap gap-4 text-white-50 small fw-medium">
                    <div class="d-flex align-items-center gap-2">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                        {{ $vacancy->location }}
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
                        {{ $vacancy->type }}
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                        {{ $vacancy->department ?? 'Geral' }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<div class="container py-lg-4 mb-5">
    <div class="row g-5">
        <!-- Content Column -->
        <div class="col-lg-7">
            <div class="vacancy-content pe-lg-4">
                <div class="mb-5">
                    <h2 class="h4 fw-bold mb-3" style="color: var(--brand);">Descrição da Vaga</h2>
                    <div class="text-muted recruitment-text">
                        {!! $vacancy->description !!}
                    </div>
                </div>

                @if($vacancy->requirements)
                <div class="mb-5">
                    <h2 class="h4 fw-bold mb-4" style="color: var(--brand);">Requisitos</h2>
                    <div class="text-muted recruitment-text">
                        {!! $vacancy->requirements !!}
                    </div>
                </div>
                @endif

                @if($vacancy->benefits)
                <div class="mb-5">
                    <h2 class="h4 fw-bold mb-4" style="color: var(--brand);">Benefícios</h2>
                    <div class="text-muted recruitment-text">
                        {!! $vacancy->benefits !!}
                    </div>
                </div>
                @endif
            </div>
        </div>

        <!-- Form Column -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-lg rounded-4 overflow-hidden sticky-lg-top" style="top: 100px; z-index: 10;">
                <div class="card-body p-4 p-md-5">
                    <h3 class="h4 fw-bold mb-4 text-brand">Candidatar-se</h3>
                    
                    @if(session('success'))
                        <div class="alert alert-success alert-dismissible fade show mb-4 rounded-3 border-0 shadow-sm" role="alert">
                            {{ session('success') }}
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    @endif

                    <form action="{{ route('site.vacancies.apply', $vacancy->id) }}" method="POST" enctype="multipart/form-data">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label small fw-semibold text-muted">Nome Completo</label>
                            <input type="text" name="name" class="form-control bg-light border-0 shadow-none ps-3 py-2" value="{{ old('name') }}" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold text-muted">E-mail Corporativo ou Pessoal</label>
                            <input type="email" name="email" class="form-control bg-light border-0 shadow-none ps-3 py-2" value="{{ old('email') }}" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold text-muted">Telefone / WhatsApp</label>
                            <input type="text" name="phone" id="phone_num" class="form-control bg-light border-0 shadow-none ps-3 py-2" placeholder="(00) 00000-0000" value="{{ old('phone') }}" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold text-muted">LinkedIn (opcional)</label>
                            <input type="url" name="linkedin_url" class="form-control bg-light border-0 shadow-none ps-3 py-2" placeholder="https://linkedin.com/in/..." value="{{ old('linkedin_url') }}">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold text-muted">Currículo (PDF ou DOCX)</label>
                            <input type="file" name="resume" class="form-control bg-light border-0 shadow-none ps-3 py-2" accept=".pdf,.doc,.docx" required>
                            <div class="form-text x-small text-muted mt-1">Tamanho máximo de 10MB</div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label small fw-semibold text-muted">Mensagem / Observação (opcional)</label>
                            <textarea name="message" rows="3" class="form-control bg-light border-0 shadow-none ps-3 py-2" placeholder="Destaque brevemente sua experiência mais relevante ou motivação para integrar o time..."></textarea>
                        </div>
                        <button type="submit" class="btn btn-brand w-100 btn-lg shadow-sm fw-bold mb-3">Enviar Candidatura</button>
                        <p class="small text-muted mb-0" style="font-size: 0.72rem; line-height: 1.4;">
                            Os dados e arquivos enviados são tratados exclusivamente para fins de recrutamento, sob protocolos de sigilo em conformidade com a LGPD.
                        </p>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .recruitment-text h3 { font-size: 1.15rem; font-weight: 700; color: var(--brand); margin-bottom: 1rem; }
    .recruitment-text ul { padding-left: 1.25rem; margin-bottom: 1.5rem; }
    .recruitment-text li { margin-bottom: 0.5rem; }
    .recruitment-text p { margin-bottom: 1.5rem; line-height: 1.7; }
</style>
@endsection

@push('scripts')
<script src="https://unpkg.com/imask"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        IMask(document.getElementById('phone_num'), { mask: '(00) 00000-0000' });
    });
</script>
@endpush
