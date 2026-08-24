@extends('site.layout')

@section('title', $vacancy->title . ' — BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center mb-5" style="min-height: 35vh; overflow: hidden; background: var(--brand-strong);">
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
                    @if($vacancy->work_model)
                        <div class="d-flex align-items-center gap-2">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
                            {{ \App\Enums\VacancyWorkModel::labelFor($vacancy->work_model) }}
                        </div>
                    @endif
                    @if($vacancy->positions > 1)
                        <div class="d-flex align-items-center gap-2">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-3-3.87"></path><path d="M22 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path></svg>
                            {{ $vacancy->positions }} {{ $vacancy->positions === 1 ? 'vaga' : 'vagas' }}
                        </div>
                    @endif
                </div>
                @if($vacancy->salary_visible && $vacancy->salaryRangeLabel())
                    <div class="mt-3">
                        <span class="badge fs-6" style="background: rgba(160,110,40,0.18); color: #E6E4E4; border: 1px solid rgba(160,110,40,0.30); padding: 0.5rem 1rem;">{{ $vacancy->salaryRangeLabel() }}</span>
                    </div>
                @endif
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
                        <x-safe-html :html="$vacancy->description" />
                    </div>
                </div>

                @if($vacancy->requirements)
                <div class="mb-5">
                    <h2 class="h4 fw-bold mb-4" style="color: var(--brand);">Requisitos</h2>
                    <div class="text-muted recruitment-text">
                        <x-safe-html :html="$vacancy->requirements" />
                    </div>
                </div>
                @endif

                @if($vacancy->benefits)
                <div class="mb-5">
                    <h2 class="h4 fw-bold mb-4" style="color: var(--brand);">Benefícios</h2>
                    <div class="text-muted recruitment-text">
                        <x-safe-html :html="$vacancy->benefits" />
                    </div>
                </div>
                @endif

                <div class="small text-muted" style="border-top: 1px solid rgba(9,27,35,0.10); padding-top: 1rem;">
                    @if($vacancy->published_at)
                        Publicada em {{ $vacancy->published_at->format('d/m/Y') }}
                    @endif
                    @if($vacancy->expires_at)
                        · Expira em {{ $vacancy->expires_at->format('d/m/Y') }}
                    @endif
                </div>
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

                    @if($errors->any())
                        <div class="alert alert-danger mb-4 rounded-3 border-0 shadow-sm" role="alert">
                            <ul class="mb-0 ps-3">
                                @foreach($errors->all() as $err)
                                    <li class="small">{{ $err }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form action="{{ route('site.vacancies.apply', $vacancy->slug) }}" method="POST" enctype="multipart/form-data" novalidate>
                        @csrf
                        {{-- Honeypot field: must remain empty --}}
                        <div class="d-none" aria-hidden="true">
                            <label for="website">Website</label>
                            <input type="text" name="website" id="website" tabindex="-1" autocomplete="off" value="{{ old('website') }}">
                        </div>
                        <div class="mb-3">
                            <label for="apply_name" class="form-label small fw-semibold text-muted">Nome Completo <span class="text-danger">*</span></label>
                            <input type="text" id="apply_name" name="name" class="form-control bg-light border-0 shadow-none ps-3 py-2 @error('name') is-invalid @enderror" value="{{ old('name') }}" required aria-required="true" autocomplete="name">
                            @error('name')<div class="invalid-feedback d-block small">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label for="apply_email" class="form-label small fw-semibold text-muted">E-mail <span class="text-danger">*</span></label>
                            <input type="email" id="apply_email" name="email" class="form-control bg-light border-0 shadow-none ps-3 py-2 @error('email') is-invalid @enderror" value="{{ old('email') }}" required aria-required="true" autocomplete="email">
                            @error('email')<div class="invalid-feedback d-block small">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label for="phone_num" class="form-label small fw-semibold text-muted">Telefone / WhatsApp <span class="text-danger">*</span></label>
                            <input type="text" name="phone" id="phone_num" class="form-control bg-light border-0 shadow-none ps-3 py-2 @error('phone') is-invalid @enderror" placeholder="(00) 00000-0000" value="{{ old('phone') }}" required aria-required="true" autocomplete="tel">
                            @error('phone')<div class="invalid-feedback d-block small">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label for="apply_linkedin" class="form-label small fw-semibold text-muted">LinkedIn (opcional)</label>
                            <input type="url" id="apply_linkedin" name="linkedin_url" class="form-control bg-light border-0 shadow-none ps-3 py-2 @error('linkedin_url') is-invalid @enderror" placeholder="https://linkedin.com/in/..." value="{{ old('linkedin_url') }}" autocomplete="url">
                            @error('linkedin_url')<div class="invalid-feedback d-block small">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label for="apply_resume" class="form-label small fw-semibold text-muted">Currículo (PDF ou DOCX) <span class="text-danger">*</span></label>
                            <input type="file" id="apply_resume" name="resume" class="form-control bg-light border-0 shadow-none ps-3 py-2 @error('resume') is-invalid @enderror" accept=".pdf,.doc,.docx" required aria-required="true">
                            @error('resume')<div class="invalid-feedback d-block small">{{ $message }}</div>@enderror
                            <div class="form-text x-small text-muted mt-1">Tamanho máximo de 10MB</div>
                        </div>
                        <div class="mb-4">
                            <label for="apply_message" class="form-label small fw-semibold text-muted">Mensagem / Observação (opcional)</label>
                            <textarea id="apply_message" name="message" rows="3" class="form-control bg-light border-0 shadow-none ps-3 py-2 @error('message') is-invalid @enderror" placeholder="Destaque brevemente sua experiência mais relevante ou motivação para integrar o time...">{{ old('message') }}</textarea>
                            @error('message')<div class="invalid-feedback d-block small">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-4 form-check">
                            <input type="checkbox" class="form-check-input @error('lgpd_consent') is-invalid @enderror" id="lgpd_consent" name="lgpd_consent" value="1" required aria-required="true" {{ old('lgpd_consent') ? 'checked' : '' }}>
                            <label class="form-check-label small text-muted" for="lgpd_consent" style="font-size: 0.75rem; line-height: 1.4;">
                                Li e concordo com o tratamento dos meus dados para fins de recrutamento e seleção, conforme a Política de Privacidade. <span class="text-danger">*</span>
                            </label>
                            @error('lgpd_consent')<div class="invalid-feedback d-block small">{{ $message }}</div>@enderror
                        </div>
                        <button type="submit" class="btn btn-brand w-100 btn-lg shadow-sm fw-bold mb-3">Enviar Candidatura</button>
                        <p class="small text-muted mb-0" style="font-size: 0.72rem; line-height: 1.4;">
                            Os dados enviados serão tratados conforme a Política de Privacidade da BSI Capital, as normas aplicáveis e as rotinas internas de recrutamento e seleção.
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
@vite('resources/js/imask.js')
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
    document.addEventListener('DOMContentLoaded', function () {
        const el = document.getElementById('phone_num');
        if (el && window.IMask) { IMask(el, { mask: '(00) 00000-0000' }); }
    });
</script>
@endpush
