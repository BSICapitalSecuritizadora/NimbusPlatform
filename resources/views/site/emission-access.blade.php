@extends('site.layout')

@section('title', ($access ? 'Validar acesso à operação' : 'Solicitar acesso à operação') . ' - BSI Capital')

@section('content')
@php
    $codeLength = max(4, (int) config('emissions.access.code_length', 6));
    $maskedEmail = null;

    if ($access) {
        [$localPart, $domain] = array_pad(explode('@', (string) $access->requester_email, 2), 2, '');
        $visibleLocalPart = mb_substr($localPart, 0, 2);
        $maskedEmail = $visibleLocalPart . str_repeat('*', max(mb_strlen($localPart) - 2, 2));
        $maskedEmail .= $domain !== '' ? '@' . $domain : '';
    }

    $summaryItems = [
        'Código IF' => $emission->if_code ?? '—',
        'Tipo' => $emission->type ?? '—',
        'Emissão' => $emission->emission_number ?? '—',
        'Série' => $emission->series ?? '—',
        'Emissor' => $emission->issuer ?? '—',
        'Remuneração' => $emission->formatted_remuneration ?? '—',
        'Data de emissão' => $emission->issue_date?->format('d/m/Y') ?? '—',
        'Vencimento' => $emission->maturity_date?->format('d/m/Y') ?? '—',
    ];
@endphp

<section class="py-5" style="min-height: 70vh;">
    <div class="container py-4 py-lg-5">
        <div class="row justify-content-center">
            <div class="col-xl-10">
                <div class="row g-4 align-items-stretch">
                    <div class="col-lg-5">
                        <div class="surface-card h-100 p-4 p-lg-5">
                            <div class="section-kicker mb-2">Acesso controlado</div>
                            <h1 class="h2 fw-bold text-brand mb-3">
                                {{ $access ? 'Validar acesso à operação' : 'Solicitar acesso à operação' }}
                            </h1>
                            <p class="section-copy mb-4">
                                {{ $access
                                    ? 'Confirmamos o envio do código para o e-mail informado. Valide o acesso para liberar os dados completos desta emissão.'
                                    : 'Para consultar os dados completos da operação, precisamos registrar seu nome, e-mail e telefone e enviar um código de acesso para validação.' }}
                            </p>

                            @if (session('success'))
                                <div class="alert alert-success border-0 rounded-4">{{ session('success') }}</div>
                            @endif

                            <div class="surface-card-soft p-4">
                                <div class="small text-uppercase text-muted fw-semibold mb-2">Operação</div>
                                <div class="fw-semibold fs-5 text-brand">{{ $emission->name }}</div>
                                <div class="text-muted mt-1">{{ $emission->issuer ?? 'Emissor não informado' }}</div>

                                <div class="row g-3 mt-1">
                                    @foreach ($summaryItems as $label => $value)
                                        <div class="col-sm-6">
                                            <div class="small text-uppercase text-muted fw-semibold mb-1">{{ $label }}</div>
                                            <div class="fw-semibold">{{ $value }}</div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-7">
                        <div class="surface-card h-100 p-4 p-lg-5">
                            @if ($access)
                                <div class="section-kicker mb-2">Código enviado</div>
                                <h2 class="h3 fw-bold text-brand mb-3">Confirme o código de acesso</h2>
                                <p class="section-copy mb-4">
                                    Enviamos um código de {{ $codeLength }} dígitos para <strong>{{ $maskedEmail }}</strong>.
                                    Digite-o abaixo para liberar as informações desta operação.
                                </p>

                                <form method="POST" action="{{ route('site.emissions.access.verify', $access) }}">
                                    @csrf

                                    <div class="mb-3">
                                        <label for="code" class="form-label">Código de acesso</label>
                                        <input
                                            id="code"
                                            type="text"
                                            name="code"
                                            class="form-control @error('code') is-invalid @enderror"
                                            value="{{ old('code') }}"
                                            maxlength="{{ $codeLength }}"
                                            inputmode="numeric"
                                            autocomplete="one-time-code"
                                            placeholder="{{ str_repeat('0', $codeLength) }}"
                                            required
                                        >
                                        @error('code')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>

                                    <div class="d-flex flex-column flex-sm-row gap-3 align-items-sm-center">
                                        <button type="submit" class="btn btn-brand btn-lg px-5">Validar e acessar operação</button>
                                        <a href="{{ route('site.emissions.show', $emission->if_code) }}" class="btn btn-outline-brand btn-lg px-4">
                                            Voltar
                                        </a>
                                    </div>
                                </form>
                            @else
                                <div class="section-kicker mb-2">Identificação</div>
                                <h2 class="h3 fw-bold text-brand mb-3">Informe seus dados para receber o código</h2>
                                <p class="section-copy mb-4">
                                    O acesso completo é liberado após a validação do código enviado para o e-mail informado. O telefone fica registrado para rastreabilidade do atendimento.
                                </p>

                                @error('access_request')
                                    <div class="alert alert-danger border-0 rounded-4">{{ $message }}</div>
                                @enderror

                                <form method="POST" action="{{ route('site.emissions.access.store', $emission->if_code) }}">
                                    @csrf

                                    <div class="mb-3">
                                        <label for="name" class="form-label">Nome completo</label>
                                        <input
                                            id="name"
                                            type="text"
                                            name="name"
                                            class="form-control @error('name') is-invalid @enderror"
                                            value="{{ old('name') }}"
                                            autocomplete="name"
                                            required
                                        >
                                        @error('name')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>

                                    <div class="mb-3">
                                        <label for="email" class="form-label">E-mail</label>
                                        <input
                                            id="email"
                                            type="email"
                                            name="email"
                                            class="form-control @error('email') is-invalid @enderror"
                                            value="{{ old('email') }}"
                                            autocomplete="email"
                                            required
                                        >
                                        @error('email')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>

                                    <div class="mb-4">
                                        <label for="phone" class="form-label">Telefone</label>
                                        <input
                                            id="phone"
                                            type="text"
                                            name="phone"
                                            class="form-control @error('phone') is-invalid @enderror"
                                            value="{{ old('phone') }}"
                                            inputmode="tel"
                                            autocomplete="tel"
                                            placeholder="(11) 99999-9999"
                                            required
                                        >
                                        @error('phone')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>

                                    <div class="d-flex flex-column flex-sm-row gap-3 align-items-sm-center">
                                        <button type="submit" class="btn btn-brand btn-lg px-5">Enviar código de acesso</button>
                                        <a href="{{ route('site.emissions') }}" class="btn btn-outline-brand btn-lg px-4">
                                            Voltar para emissões
                                        </a>
                                    </div>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection

@push('scripts')
    @php
        $cspNonce = \Illuminate\Support\Facades\Vite::cspNonce();
    @endphp
    <script nonce="{{ $cspNonce }}">
        document.addEventListener('DOMContentLoaded', function () {
            const phoneInput = document.getElementById('phone');

            if (! phoneInput) {
                return;
            }

            const formatBrazilianPhone = function (value) {
                const digits = value.replace(/\D/g, '').slice(0, 11);

                if (digits.length <= 2) {
                    return digits;
                }

                if (digits.length <= 6) {
                    return `(${digits.slice(0, 2)}) ${digits.slice(2)}`;
                }

                if (digits.length <= 10) {
                    return `(${digits.slice(0, 2)}) ${digits.slice(2, 6)}-${digits.slice(6)}`;
                }

                return `(${digits.slice(0, 2)}) ${digits.slice(2, 7)}-${digits.slice(7)}`;
            };

            phoneInput.value = formatBrazilianPhone(phoneInput.value);

            phoneInput.addEventListener('input', function (event) {
                event.target.value = formatBrazilianPhone(event.target.value);
            });
        });
    </script>
@endpush
