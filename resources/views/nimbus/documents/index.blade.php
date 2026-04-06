@extends('nimbus.layouts.portal')

@section('title', 'Documentos')

@section('content')
<div class="d-flex align-items-center justify-content-between mb-5">
    <div>
        <h1 class="h3 fw-bold text-dark mb-1">Documentos</h1>
        <p class="text-secondary mb-0">Acesse os arquivos disponibilizados para o seu usuário no portal.</p>
    </div>
    <a href="{{ route('nimbus.submissions.index') }}" class="nd-btn nd-btn-outline shadow-sm">
        <i class="bi bi-inbox"></i>
        <span>Meus Envios</span>
    </a>
</div>

<div class="nd-card border-0 shadow-sm rounded-4 bg-white overflow-hidden">
    <div class="nd-card-body p-0">
        @if($documents->isEmpty())
            <div class="p-5 text-center">
                <div class="d-inline-flex bg-light rounded-circle p-4 mb-3">
                    <i class="bi bi-folder2-open fs-1 text-secondary"></i>
                </div>
                <h5 class="fw-bold text-dark">Nenhum documento disponível</h5>
                <p class="text-muted mx-auto mb-0" style="max-width: 420px;">
                    Quando a nossa equipe disponibilizar arquivos para o seu usuário, eles aparecerão aqui para consulta e download.
                </p>
            </div>
        @else
            <div class="table-responsive">
                <table class="nd-table w-100 mb-0">
                    <thead>
                        <tr class="bg-light">
                            <th class="ps-4">Documento</th>
                            <th>Arquivo</th>
                            <th>Disponibilizado em</th>
                            <th class="text-end pe-4">Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($documents as $document)
                            <tr class="align-middle">
                                <td class="ps-4 py-3">
                                    <span class="d-block fw-bold text-dark">{{ $document->title }}</span>
                                    <small class="text-secondary">{{ $document->description ?: 'Sem descrição adicional.' }}</small>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center text-muted small">
                                        <i class="bi bi-file-earmark-arrow-down me-2"></i>
                                        {{ $document->file_original_name }}
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center text-muted small">
                                        <i class="bi bi-calendar3 me-2"></i>
                                        {{ $document->created_at?->format('d/m/Y H:i') ?? '-' }}
                                    </div>
                                </td>
                                <td class="text-end pe-4">
                                    <a href="{{ route('nimbus.documents.download', $document) }}" class="nd-btn nd-btn-ghost nd-btn-sm text-primary">
                                        Download <i class="bi bi-download ms-1"></i>
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
