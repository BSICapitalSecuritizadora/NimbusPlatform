@extends('nimbus.layouts.portal')
@section('title', 'Meus Envios')

@section('content')
<div class="d-flex align-items-center justify-content-between mb-5">
    <div>
        <h1 class="h3 fw-bold text-dark mb-1">Meus Envios</h1>
        <p class="text-secondary mb-0">Acompanhe o status e histórico das suas solicitações.</p>
    </div>
    <a href="{{ route('nimbus.submissions.create') }}" class="nd-btn nd-btn-gold shadow-sm hover-scale">
        <i class="bi bi-plus-lg"></i>
        <span>Novo Envio</span>
    </a>
</div>

<div class="nd-card border-0 shadow-sm rounded-4 bg-white overflow-hidden">
    <div class="nd-card-body p-0">
        @if($submissions->isEmpty())
            <div class="p-5 text-center">
                <div class="d-inline-flex bg-light rounded-circle p-4 mb-3">
                    <i class="bi bi-inbox fs-1 text-secondary"></i>
                </div>
                <h5 class="fw-bold text-dark">Nenhum envio encontrado</h5>
                <p class="text-muted mx-auto" style="max-width: 400px;">
                    Você ainda não possui envios registrados no nosso sistema. 
                    Clique no botão acima para iniciar sua primeira submissão.
                </p>
                <a href="{{ route('nimbus.submissions.create') }}" class="nd-btn nd-btn-primary mt-2">
                    Iniciar Submissão
                </a>
            </div>
        @else
            <div class="table-responsive">
                <table class="nd-table w-100 mb-0">
                    <thead>
                        <tr class="bg-light">
                            <th class="ps-4">Código / Referência</th>
                            <th>Status</th>
                            <th>Responsável</th>
                            <th>Data de Envio</th>
                            <th class="text-end pe-4">Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($submissions as $s)
                            @php
                                $statusConfig = [
                                    'label' => \App\Models\Nimbus\Submission::statusLabelFor($s->status),
                                    'class' => \App\Models\Nimbus\Submission::statusColorFor($s->status),
                                    'icon' => \App\Models\Nimbus\Submission::statusIconFor($s->status),
                                ];
                            @endphp
                            <tr class="align-middle">
                                <td class="ps-4 py-3">
                                    <span class="d-block fw-bold text-dark">#{{ $s->id }}</span>
                                    <small class="text-muted">{{ $s->company_name }}</small>
                                </td>
                                <td>
                                    <span class="nd-badge nd-badge-{{ $statusConfig['class'] }}">
                                        <i class="bi {{ $statusConfig['icon'] }}"></i>
                                        {{ $statusConfig['label'] }}
                                    </span>
                                </td>
                                <td>
                                    <span class="d-block text-dark">{{ $s->responsible_name }}</span>
                                    <small class="text-secondary">{{ $s->company_cnpj }}</small>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center text-muted small">
                                        <i class="bi bi-calendar3 me-2"></i>
                                        {{ $s->submitted_at ? $s->submitted_at->format('d/m/Y H:i') : '-' }}
                                    </div>
                                </td>
                                <td class="text-end pe-4">
                                    <a href="{{ route('nimbus.submissions.show', $s->id) }}" class="nd-btn nd-btn-ghost nd-btn-sm text-primary">
                                        Detalhes <i class="bi bi-chevron-right ms-1"></i>
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
