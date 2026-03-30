@extends('site.layout')

@section('title', 'Continuar Proposta')

@section('content')
<section class="py-5" style="min-height: 70vh;">
    <div class="container py-4 py-lg-5">
        <div class="row justify-content-center">
            <div class="col-xl-9">
                <div class="row g-4 align-items-stretch">
                    <div class="col-lg-5">
                        <div class="surface-card h-100 p-4 p-lg-5">
                            <div class="section-kicker mb-2">Acesso seguro</div>
                            <h1 class="h2 fw-bold text-brand mb-3">Continuar proposta</h1>
                            <p class="section-copy mb-4">
                                Valide o acesso com o CNPJ da empresa e o código enviado por e-mail. O processo preserva segurança, rastreabilidade e continuidade do preenchimento.
                            </p>

                            @if (session('success'))
                                <div class="alert alert-success border-0 rounded-4">{{ session('success') }}</div>
                            @endif

                            <div class="surface-card-soft p-4">
                                <div class="small text-uppercase text-muted fw-semibold mb-2">Proposta</div>
                                <div class="fw-semibold">{{ $proposal->company->name }}</div>
                                <div class="text-muted">{{ $proposal->contact->name }} • {{ $proposal->contact->email }}</div>
                                <div class="mt-3 small text-muted">CNPJ: {{ $proposal->company->cnpj }}</div>
                                <div class="small text-muted">Status atual: {{ $proposal->status_label }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-7">
                        <div class="surface-card h-100 p-4 p-lg-5">
                            <div class="section-kicker mb-2">Validação</div>
                            <h2 class="h3 fw-bold text-brand mb-3">Confirme suas credenciais de acesso</h2>
                            <p class="section-copy mb-4">
                                Esta etapa garante que apenas o proponente autorizado consiga retomar o preenchimento da proposta.
                            </p>

                            <form method="POST" action="{{ route('site.proposal.continuation.verify', $access) }}">
                                @csrf
                                <div class="mb-3">
                                    <label class="form-label">CNPJ</label>
                                    <input type="text" name="cnpj" id="cnpj" class="form-control @error('cnpj') is-invalid @enderror" value="{{ old('cnpj', $proposal->company->cnpj) }}" required>
                                    @error('cnpj') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="mb-4">
                                    <label class="form-label">Código de acesso</label>
                                    <input type="text" name="code" class="form-control @error('code') is-invalid @enderror" value="{{ old('code') }}" maxlength="6" required>
                                    @error('code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <button type="submit" class="btn btn-brand btn-lg px-5">Acessar continuação</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection

@push('scripts')
<script src="https://unpkg.com/imask"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        IMask(document.getElementById('cnpj'), { mask: '00.000.000/0000-00' });
    });
</script>
@endpush
