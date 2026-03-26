@extends('site.layout')

@section('title', 'Continuar Proposta')

@section('content')
<section class="py-5" style="background:#f3f6fb;min-height:70vh;">
    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-lg-7">
                <div class="card border-0 shadow rounded-4">
                    <div class="card-body p-4 p-md-5">
                        <div class="mb-4">
                            <h1 class="h3 fw-bold mb-2">Continuar Proposta</h1>
                            <p class="text-muted mb-0">Valide o acesso com o CNPJ da empresa e o código enviado por e-mail.</p>
                        </div>

                        @if (session('success'))
                            <div class="alert alert-success">{{ session('success') }}</div>
                        @endif

                        <div class="rounded-4 p-4 mb-4" style="background:#f8fafc;border:1px solid #dbe4f0;">
                            <div class="small text-uppercase text-muted fw-semibold mb-2">Proposta</div>
                            <div class="fw-semibold">{{ $proposal->company->name }}</div>
                            <div class="text-muted">{{ $proposal->contact->name }} • {{ $proposal->contact->email }}</div>
                            <div class="mt-2 small text-muted">CNPJ: {{ $proposal->company->cnpj }} • Status: {{ $proposal->status_label }}</div>
                        </div>

                        <form method="POST" action="{{ route('site.proposal.continuation.verify', $access) }}">
                            @csrf
                            <div class="mb-3">
                                <label class="form-label fw-semibold">CNPJ</label>
                                <input type="text" name="cnpj" id="cnpj" class="form-control @error('cnpj') is-invalid @enderror" value="{{ old('cnpj', $proposal->company->cnpj) }}" required>
                                @error('cnpj') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="mb-4">
                                <label class="form-label fw-semibold">Código de acesso</label>
                                <input type="text" name="code" class="form-control @error('code') is-invalid @enderror" value="{{ old('code') }}" maxlength="6" required>
                                @error('code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <button type="submit" class="btn btn-brand px-4">Acessar continuação</button>
                        </form>
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
