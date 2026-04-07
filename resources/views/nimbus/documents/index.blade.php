@extends('nimbus.layouts.portal')

@section('title', 'Biblioteca de Arquivos')

@section('content')
<!-- Page Header -->
<div class="row align-items-center mb-5">
    <div class="col-12">
        <h1 class="h3 fw-bold text-dark mb-1">Biblioteca de Arquivos</h1>
        <p class="text-secondary mb-0">Manuais, políticas e documentos oficiais para download.</p>
    </div>
</div>

<div class="row g-5">
    <!-- Sidebar / Filters -->
    <div class="col-lg-3">
        <div class="sticky-top" style="top: 2rem; z-index: 10;">
            <form action="{{ route('nimbus.documents.index') }}" method="get">
                <!-- Search -->
                <div class="mb-5">
                    <label class="form-label small fw-bold text-uppercase text-secondary mb-2" style="letter-spacing: 1px;">Buscar</label>
                    <div class="nd-input-group position-relative">
                        <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-3 text-muted opacity-50" style="z-index: 4;"></i>
                        <input type="text" name="q" class="form-control border-0 bg-white shadow-sm ps-5 py-3 rounded-3" 
                               placeholder="Palavras-chave..." 
                               value="{{ $term }}">
                        @if(!empty($currentCategory))
                            <input type="hidden" name="category_id" value="{{ $currentCategory }}">
                        @endif
                    </div>
                </div>
                
                <!-- Categories -->
                <div>
                    <label class="form-label small fw-bold text-uppercase text-secondary mb-3" style="letter-spacing: 1px;">Categorias</label>
                    <div class="d-flex flex-column gap-2">
                        <a href="{{ route('nimbus.documents.index', ['q' => $term]) }}" 
                           class="btn text-start py-2 px-3 rounded-3 d-flex align-items-center justify-content-between border-0 {{ empty($currentCategory) ? 'bg-primary text-white shadow-sm fw-medium' : 'bg-white text-secondary shadow-sm hover-shadow' }}"
                           style="transition: all 0.2s;">
                            <span><i class="bi bi-grid me-2 {{ empty($currentCategory) ? '' : 'opacity-50' }}"></i> Todas</span>
                            @if(empty($currentCategory))
                                <i class="bi bi-check-lg small"></i>
                            @endif
                        </a>
                        
                        @foreach ($categories as $cat)
                            @php $isActive = ($currentCategory == $cat->id); @endphp
                            <a href="{{ route('nimbus.documents.index', ['category_id' => $cat->id, 'q' => $term]) }}" 
                               class="btn text-start py-2 px-3 rounded-3 d-flex align-items-center justify-content-between border-0 {{ $isActive ? 'bg-primary text-white shadow-sm fw-medium' : 'bg-white text-secondary shadow-sm hover-shadow' }}"
                               style="transition: all 0.2s;">
                                <span class="text-truncate">
                                    <i class="bi bi-folder2-open me-2 {{ $isActive ? '' : 'opacity-50' }}"></i> 
                                    {{ $cat->name }}
                                </span>
                                @if($isActive)
                                    <i class="bi bi-check-lg small"></i>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </div>
                
                @if (!empty($term) || !empty($currentCategory))
                    <div class="mt-4 pt-4 border-top border-light-subtle">
                        <a href="{{ route('nimbus.documents.index') }}" class="btn btn-sm btn-outline-danger w-100 border-0 bg-danger-subtle text-danger rounded-3 py-2">
                            <i class="bi bi-x-lg me-1"></i> Limpar filtros
                        </a>
                    </div>
                @endif
            </form>
        </div>
    </div>

    <!-- Results -->
    <div class="col-lg-9">
        
        <!-- Tabs -->
        <ul class="nav nav-pills mb-4 gap-2" id="docsTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active rounded-pill px-4 fw-medium flex-fill" id="general-tab" data-bs-toggle="pill" data-bs-target="#general" type="button" role="tab" aria-controls="general" aria-selected="true">
                    <i class="bi bi-building me-2"></i> Documentos Gerais
                    @if ($documents->isNotEmpty())
                        <span class="badge bg-light text-dark ms-2 rounded-pill">{{ $documents->count() }}</span>
                    @endif
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link rounded-pill px-4 fw-medium flex-fill" id="private-tab" data-bs-toggle="pill" data-bs-target="#private" type="button" role="tab" aria-controls="private" aria-selected="false">
                    <i class="bi bi-person-lock me-2"></i> Meus Documentos
                    @if ($userDocs->isNotEmpty())
                        <span class="badge bg-light text-dark ms-2 rounded-pill">{{ $userDocs->count() }}</span>
                    @endif
                </button>
            </li>
        </ul>

        <div class="tab-content" id="docsTabsContent">
            <!-- General Documents Tab -->
            <div class="tab-pane fade show active" id="general" role="tabpanel" aria-labelledby="general-tab">
                @if ($documents->isEmpty())
                    <div class="nd-card border-0 shadow-sm text-center py-5 rounded-4 p-5 bg-white">
                        <div class="nd-card-body">
                            <div class="mb-4">
                                <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-light border p-4">
                                    <i class="bi bi-folder-x text-muted opacity-25 display-4"></i>
                                </div>
                            </div>
                            <h5 class="fw-bold text-dark mb-2">Nenhum documento encontrado</h5>
                            <p class="text-secondary small mb-0 w-75 mx-auto">Não encontramos arquivos com os filtros atuais. Tente buscar por outros termos ou categorias.</p>
                        </div>
                    </div>
                @else
                    <div class="row g-4">
                        @foreach ($documents as $doc)
                            <div class="col-md-6 col-xl-4">
                                <div class="card h-100 border-0 shadow-sm rounded-4 position-relative bg-white overflow-hidden" style="transition: transform 0.2s, box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-5px)'; this.style.boxShadow='0 1rem 3rem rgba(0,0,0,.175)'" onmouseout="this.style.transform='none'; this.style.boxShadow='0 .125rem .25rem rgba(0,0,0,.075)'">
                                    <div class="card-body p-4 d-flex flex-column">
                                        <div class="d-flex align-items-start justify-content-between mb-4">
                                            <div class="rounded-3 bg-primary text-white d-flex align-items-center justify-content-center shadow-sm" style="width: 48px; height: 56px;">
                                                <i class="bi bi-file-earmark-text-fill fs-4"></i>
                                            </div>
                                            @if ($doc->category)
                                                <span class="badge bg-light text-secondary border fw-medium px-2 py-1 rounded-pill small">
                                                    {{ $doc->category->name }}
                                                </span>
                                            @endif
                                        </div>
                                        
                                        <h5 class="card-title h6 text-dark fw-bold mb-2">
                                            <a href="#" onclick="openPreview('{{ route('nimbus.documents.general.preview', $doc) }}', '{{ route('nimbus.documents.general.download', $doc) }}', '{{ addslashes($doc->title) }}'); return false;" class="text-decoration-none text-dark stretched-link">
                                                {{ $doc->title }}
                                            </a>
                                        </h5>
                                        
                                        <p class="card-text small text-secondary mb-4 flex-grow-1 opacity-75" style="display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                                            {{ $doc->description ?? 'Sem descrição disponível.' }}
                                        </p>
                                        
                                        <div class="mt-auto d-flex align-items-end justify-content-between pt-3 border-top border-light-subtle w-100">
                                            <div class="d-flex align-items-center gap-2 text-muted small fw-medium" style="font-size: 0.75rem;">
                                                <i class="bi bi-calendar3"></i>
                                                {{ $doc->published_at ? $doc->published_at->format('d/m/Y') : ($doc->created_at ? $doc->created_at->format('d/m/Y') : '-') }}
                                            </div>
                                            
                                            <div class="d-flex gap-1 position-relative" style="z-index: 2;">
                                                <button type="button" 
                                                        class="btn btn-sm btn-light text-primary border rounded-circle d-flex align-items-center justify-content-center shadow-sm"
                                                        style="width: 34px; height: 34px; transition: all 0.2s;"
                                                        onmouseover="this.classList.add('bg-primary', 'text-white'); this.classList.remove('text-primary');"
                                                        onmouseout="this.classList.remove('bg-primary', 'text-white'); this.classList.add('text-primary');"
                                                        onclick="openPreview('{{ route('nimbus.documents.general.preview', $doc) }}', '{{ route('nimbus.documents.general.download', $doc) }}', '{{ addslashes($doc->title) }}')"
                                                        title="Visualizar">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <a href="{{ route('nimbus.documents.general.download', $doc) }}" 
                                                   class="btn btn-sm btn-light text-secondary border rounded-circle d-flex align-items-center justify-content-center shadow-sm"
                                                   style="width: 34px; height: 34px; transition: all 0.2s;"
                                                   onmouseover="this.classList.add('bg-dark', 'text-white'); this.classList.remove('text-secondary');"
                                                   onmouseout="this.classList.remove('bg-dark', 'text-white'); this.classList.add('text-secondary');"
                                                   title="Baixar">
                                                    <i class="bi bi-download"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
            
            <!-- Private User Documents Tab -->
            <div class="tab-pane fade" id="private" role="tabpanel" aria-labelledby="private-tab">
                @if ($userDocs->isEmpty())
                    <div class="nd-card border-0 shadow-sm text-center py-5 rounded-4 p-5 bg-white">
                        <div class="nd-card-body">
                            <div class="mb-4">
                                <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-light border p-4">
                                    <i class="bi bi-person-fill-lock text-muted opacity-25 display-4"></i>
                                </div>
                            </div>
                            <h5 class="fw-bold text-dark mb-2">Nenhum documento privado</h5>
                            <p class="text-secondary small mb-0 w-75 mx-auto">Você não possui documentos exclusivos vinculados à sua conta no momento.</p>
                        </div>
                    </div>
                @else
                    <div class="row g-4">
                        @foreach ($userDocs as $doc)
                            <div class="col-md-6 col-xl-4">
                                <div class="card h-100 border-0 shadow-sm rounded-4 position-relative bg-white overflow-hidden" style="border-top: 3px solid #d4af37 !important; transition: transform 0.2s, box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-5px)'; this.style.boxShadow='0 1rem 3rem rgba(0,0,0,.175)'" onmouseout="this.style.transform='none'; this.style.boxShadow='0 .125rem .25rem rgba(0,0,0,.075)'">
                                    <div class="card-body p-4 d-flex flex-column">
                                        <div class="d-flex align-items-start justify-content-between mb-4">
                                            <div class="rounded-3 bg-dark text-white d-flex align-items-center justify-content-center shadow-sm" style="width: 48px; height: 56px;">
                                                <i class="bi bi-shield-lock-fill fs-4"></i>
                                            </div>
                                            <span class="badge border fw-medium px-2 py-1 rounded-pill small" style="background-color: #f9f5e8; color: #b8911c; border-color: #ebd188 !important;">
                                                Exclusivo
                                            </span>
                                        </div>
                                        
                                        <h5 class="card-title h6 text-dark fw-bold mb-2">
                                            {{ $doc->title }}
                                        </h5>
                                        
                                        <p class="card-text small text-secondary mb-4 flex-grow-1 opacity-75" style="display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                                            {{ $doc->description ?? 'Documento privado.' }}
                                        </p>
                                        
                                        <div class="mt-auto d-flex align-items-end justify-content-between pt-3 border-top border-light-subtle w-100">
                                            <div class="d-flex align-items-center gap-2 text-muted small fw-medium" style="font-size: 0.75rem;">
                                                <i class="bi bi-calendar3"></i>
                                                {{ $doc->created_at ? $doc->created_at->format('d/m/Y') : '-' }}
                                            </div>
                                            
                                            <div class="d-flex gap-1 position-relative" style="z-index: 2;">
                                                <button type="button" 
                                                        class="btn btn-sm btn-light text-dark border rounded-circle d-flex align-items-center justify-content-center shadow-sm"
                                                        style="width: 34px; height: 34px; transition: all 0.2s;"
                                                        onmouseover="this.classList.add('bg-dark', 'text-white');"
                                                        onmouseout="this.classList.remove('bg-dark', 'text-white');"
                                                        onclick="openPreview('{{ route('nimbus.documents.preview', $doc) }}', '{{ route('nimbus.documents.download', $doc) }}', '{{ addslashes($doc->title) }}')"
                                                        title="Visualizar">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <a href="{{ route('nimbus.documents.download', $doc) }}" 
                                                   class="btn btn-sm btn-light text-dark border rounded-circle d-flex align-items-center justify-content-center shadow-sm"
                                                   style="width: 34px; height: 34px; transition: all 0.2s;"
                                                   onmouseover="this.classList.add('bg-dark', 'text-white');"
                                                   onmouseout="this.classList.remove('bg-dark', 'text-white');"
                                                   title="Baixar">
                                                    <i class="bi bi-download"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

<!-- Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable" style="height: 90vh;">
        <div class="modal-content h-100 border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-bottom py-3 bg-white">
                <div class="d-flex align-items-center gap-2">
                    <div class="bg-primary-subtle text-primary rounded p-2 d-flex align-items-center justify-content-center">
                        <i class="bi bi-file-earmark-text"></i>
                    </div>
                    <h5 class="modal-title h6 fw-bold mb-0" id="previewModalLabel">Visualizar Documento</h5>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <a href="#" id="downloadBtn" class="btn btn-sm btn-primary rounded-pill px-3" download>
                        <i class="bi bi-download me-1"></i> Baixar
                    </a>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            <div class="modal-body p-0 bg-light position-relative">
                <div id="loader" class="position-absolute top-50 start-50 translate-middle text-center">
                    <div class="spinner-border text-primary mb-2" role="status"></div>
                    <div class="small text-muted">Carregando visualização...</div>
                </div>
                <iframe src="" id="previewFrame" class="w-100 h-100 border-0" onload="document.getElementById('loader').style.display='none'"></iframe>
            </div>
        </div>
    </div>
</div>

<script>
function openPreview(previewUrl, downloadUrl, title) {
    const modal = new bootstrap.Modal(document.getElementById('previewModal'));
    const frame = document.getElementById('previewFrame');
    const label = document.getElementById('previewModalLabel');
    const download = document.getElementById('downloadBtn');
    const loader = document.getElementById('loader');
    
    // Reset state
    frame.src = 'about:blank';
    loader.style.display = 'block';
    
    // Set Data
    label.textContent = title;
    
    frame.src = previewUrl;
    download.href = downloadUrl;
    
    modal.show();
}
</script>
@endsection
