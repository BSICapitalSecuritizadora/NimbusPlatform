@extends('nimbus.layouts.portal')
@section('title', 'Detalhes da Solicitação')

@section('content')
<div class="d-flex align-items-center justify-content-between mb-5">
    <div class="d-flex align-items-center gap-3">
        <a href="{{ route('nimbus.submissions.index') }}" class="btn btn-light rounded-circle p-2 shadow-sm">
            <i class="bi bi-arrow-left fs-4 text-secondary line-height-1"></i>
        </a>
        <div>
            <h1 class="h3 fw-bold text-dark mb-0">Solicitação #{{ $submission->id }}</h1>
            <p class="text-secondary small mb-0 mt-1">
                Enviada em {{ $submission->submitted_at ? $submission->submitted_at->format('d/m/Y \à\s H:i') : '-' }}
            </p>
        </div>
    </div>
    
    @php
        $statusConfig = [
            'label' => \App\Models\Nimbus\Submission::statusLabelFor($submission->status),
            'class' => \App\Models\Nimbus\Submission::statusColorFor($submission->status),
            'icon' => \App\Models\Nimbus\Submission::statusIconFor($submission->status),
        ];
    @endphp
    <div class="nd-badge nd-badge-{{ $statusConfig['class'] }} fs-6 py-2 px-3">
        <i class="bi {{ $statusConfig['icon'] }} me-1"></i>
        {{ $statusConfig['label'] }}
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <!-- Detalhes da Empresa -->
        <div class="nd-card border-0 shadow-sm rounded-4 mb-4">
            <div class="nd-card-header bg-white border-bottom p-4">
                <h5 class="nd-card-title fw-bold text-dark mb-0">Dados Gerais</h5>
            </div>
            <div class="nd-card-body p-4">
                <div class="row g-4">
                    <div class="col-md-6">
                        <label class="small text-muted text-uppercase fw-bold ls-1 mb-1">Empresa</label>
                        <div class="fw-medium text-dark">{{ $submission->company_name }}</div>
                        <div class="small text-secondary">{{ $submission->company_cnpj }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="small text-muted text-uppercase fw-bold ls-1 mb-1">Responsável</label>
                        <div class="fw-medium text-dark">{{ $submission->responsible_name }}</div>
                        <div class="small text-secondary">{{ $submission->phone }}</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sócios -->
        <div class="nd-card border-0 shadow-sm rounded-4 mb-4">
            <div class="nd-card-header bg-white border-bottom p-4">
                <h5 class="nd-card-title fw-bold text-dark mb-0">Composição Societária</h5>
            </div>
            <div class="nd-card-body p-0">
                @if($submission->shareholders->isEmpty())
                    <div class="p-4 text-center text-muted">Não há sócios registrados.</div>
                @else
                    <table class="nd-table w-100 mb-0">
                        <thead>
                            <tr class="bg-light">
                                <th class="ps-4">Nome</th>
                                <th>Documento</th>
                                <th class="pe-4 text-end">Participação</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($submission->shareholders as $share)
                                <tr>
                                    <td class="ps-4 py-3 text-dark fw-medium">{{ $share->name }}</td>
                                    <td class="text-secondary">{{ $share->document_cpf ?? $share->document_cnpj ?? '-' }}</td>
                                    <td class="pe-4 text-end text-dark fw-bold">{{ number_format($share->percentage, 2) }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <!-- Documentos -->
        <div class="nd-card border-0 shadow-sm rounded-4 sticky-top" style="top: 100px;">
            <div class="nd-card-header bg-white border-bottom p-4">
                <h5 class="nd-card-title fw-bold text-dark mb-0">Arquivos Anexos</h5>
            </div>
            <div class="nd-card-body p-0">
                <ul class="list-group list-group-flush">
                    @forelse($submission->files as $file)
                        <li class="list-group-item d-flex justify-content-between align-items-center p-4 border-bottom border-light-subtle">
                            <div class="d-flex align-items-center mw-100 pe-3 overflow-hidden">
                                <div class="bg-light text-danger rounded p-2 me-3 fs-4">
                                    <i class="bi bi-file-earmark-pdf-fill"></i>
                                </div>
                                <div class="text-truncate">
                                    <div class="fw-bold text-dark text-truncate">{{ $file->requirement_type }}</div>
                                    <small class="text-muted d-block text-truncate">{{ $file->original_filename }}</small>
                                </div>
                            </div>
                            <!-- Implementar rota de download seguro se desejado futuramente -->
                            <!-- <a href="#" class="btn btn-sm btn-light border rounded-circle text-primary shadow-sm" title="Download">
                                <i class="bi bi-download"></i>
                            </a> -->
                        </li>
                    @empty
                        <li class="list-group-item p-4 text-center text-muted">Nenhum arquivo enviado.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection
