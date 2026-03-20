@extends('site.layout')

@section('title', $emission->name . ' — Detalhes da Emissão — BSI Capital')

@section('content')
<div class="bg-white border-bottom py-5">
    <div class="container">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-4">
            <div class="d-flex align-items-center gap-4">
                <div class="bg-light rounded p-3 d-flex align-items-center justify-content-center shadow-sm" style="width: 80px; height: 60px;">
                    @if($emission->logo_path)
                        <img src="{{ Storage::url($emission->logo_path) }}" alt="{{ $emission->name }}" style="max-height: 100%; max-width: 100%; object-fit: contain;">
                    @else
                        <span class="h4 mb-0 text-brand fw-bold">{{ $emission->type }}</span>
                    @endif
                </div>
                <div>
                    <nav aria-label="breadcrumb" class="mb-2">
                        <ol class="breadcrumb mb-0 small">
                            <li class="breadcrumb-item"><a href="{{ route('site.emissions') }}" class="text-decoration-none text-muted">Emissões</a></li>
                            <li class="breadcrumb-item active text-brand fw-medium" aria-current="page">{{ $emission->if_code }}</li>
                        </ol>
                    </nav>
                    <h1 class="h2 fw-bold text-dark mb-1">{{ $emission->name }}</h1>
                    <div class="d-flex flex-wrap gap-3 small text-muted">
                        <span><strong>Código IF:</strong> {{ $emission->if_code }}</span>
                        <span><strong>ISIN:</strong> {{ $emission->isin_code ?? '—' }}</span>
                        <span class="badge {{ $emission->status === 'active' ? 'bg-success' : ($emission->status === 'closed' ? 'bg-secondary' : 'bg-warning') }} bg-opacity-10 text-{{ $emission->status === 'active' ? 'success' : ($emission->status === 'closed' ? 'secondary' : 'warning') }} rounded-pill px-3">
                            {{ $emission->status_label }}
                        </span>
                    </div>
                </div>
            </div>
            <div>
                <a href="{{ route('site.contact') }}" class="btn btn-brand rounded-pill px-4 py-2">Falar com um especialista</a>
            </div>
        </div>
    </div>
</div>

<section class="py-5 bg-light-subtle" style="min-height: 60vh;">
    <div class="container">
        <div class="row g-5">
            <div class="col-lg-8">
                <!-- Characteristics Section -->
                <div class="card border-0 shadow-sm mb-5" style="border-radius: 16px;">
                    <div class="card-body p-4 p-md-5">
                        <h2 class="h4 fw-bold mb-4">Características da Operação</h2>
                        <div class="row g-4">
                            @php
                                $details = [
                                    'Emissor' => $emission->issuer,
                                    'Série' => $emission->series,
                                    'Remuneração' => $emission->remuneration,
                                    'Oferta' => $emission->offer_type,
                                    'Agente Fiduciário' => $emission->trustee_agent,
                                    'Data de Emissão' => optional($emission->issue_date)->format('d/m/Y'),
                                    'Data de Vencimento' => optional($emission->maturity_date)->format('d/m/Y'),
                                    'Regime Fiduciário' => $emission->fiduciary_regime ? 'Sim' : 'Não',
                                    'Quantidade Emitida' => number_format($emission->issued_quantity, 0, ',', '.'),
                                    'Preço Unitário' => 'R$ ' . number_format($emission->issued_price, 2, ',', '.'),
                                ];
                            @endphp

                            @foreach($details as $label => $value)
                                <div class="col-sm-6">
                                    <div class="border-bottom pb-2">
                                        <div class="text-muted small mb-1">{{ $label }}</div>
                                        <div class="fw-bold text-dark">{{ $value ?? '—' }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        
                        @if($emission->description)
                            <div class="mt-5">
                                <h3 class="h5 fw-bold mb-3">Sobre a Operação</h3>
                                <div class="text-muted leading-relaxed">
                                    {!! nl2br(e($emission->description)) !!}
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Documents Section -->
                <div class="card border-0 shadow-sm" style="border-radius: 16px;">
                    <div class="card-body p-4 p-md-5">
                        <h2 class="h4 fw-bold mb-4">Documentos e Relatórios</h2>
                        @if($emission->documents->count() > 0)
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="border-0 small text-muted fw-bold">DATA</th>
                                            <th class="border-0 small text-muted fw-bold">TÍTULO DO DOCUMENTO</th>
                                            <th class="border-0 text-end px-4 small text-muted fw-bold">AÇÕES</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($emission->documents as $doc)
                                            <tr>
                                                <td class="text-muted small">{{ optional($doc->published_at)->format('d/m/Y') }}</td>
                                                <td>
                                                    <div class="fw-bold text-dark">{{ $doc->title }}</div>
                                                    @if($doc->description)
                                                        <div class="small text-muted text-truncate" style="max-width: 300px;">{{ $doc->description }}</div>
                                                    @endif
                                                </td>
                                                <td class="text-end px-4">
                                                    <a href="{{ Storage::url($doc->file_path) }}" target="_blank" class="btn btn-sm btn-outline-brand rounded-pill px-3">
                                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-1"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
                                                        Download
                                                    </a>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <div class="text-center py-5">
                                <div class="text-muted mb-3">Nenhum documento disponível para esta operação no momento.</div>
                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" class="text-muted opacity-25"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <!-- Sidebar Info / Contacts -->
                <div class="card border-0 shadow-sm p-4 mb-4" style="border-radius: 16px; background: var(--brand); color: white;">
                    <h3 class="h5 fw-bold mb-3">Dúvidas?</h3>
                    <p class="small opacity-75 mb-4">Entre em contato com nossa equipe de relações com investidores para mais informações sobre esta operação.</p>
                    <a href="mailto:ri@bsicapital.com.br" class="btn btn-light rounded-pill w-100 fw-bold">E-mail RI</a>
                </div>

                <div class="card border-0 shadow-sm p-4" style="border-radius: 16px;">
                    <h3 class="h6 fw-bold mb-3 text-dark">Informações Extras</h3>
                    <ul class="list-unstyled mb-0 small">
                        <li class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Segmento</span>
                            <span class="fw-bold text-dark">{{ $emission->segment ?? '—' }}</span>
                        </li>
                        <li class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Concentração</span>
                            <span class="fw-bold text-dark">{{ $emission->concentration ?? '—' }}</span>
                        </li>
                        <li class="d-flex justify-content-between">
                            <span class="text-muted">Volume Total</span>
                            <span class="fw-bold text-dark">R$ {{ number_format($emission->issued_volume, 0, ',', '.') }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection
