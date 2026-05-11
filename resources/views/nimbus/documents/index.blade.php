@extends('nimbus.layouts.portal')

@section('title', 'Biblioteca de Arquivos')

@section('content')
@php
    $generalDocumentsCount = $documents->count();
    $privateDocumentsCount = $userDocs->count();
    $categoryCount = $categories->count();
    $cataloguedDocumentsCount = (int) $categories->sum('active_documents_count');
    $activeFiltersCount = collect([$term, $currentCategory])->filter(static fn (mixed $value): bool => filled($value))->count();
    $selectedCategory = filled($currentCategory) ? $categories->firstWhere('id', (int) $currentCategory) : null;
    $formatFileSize = static function (mixed $bytes): string {
        if (! is_numeric($bytes) || (int) $bytes <= 0) {
            return 'Tamanho indisponível';
        }

        $size = (float) $bytes;
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return number_format($size, $unitIndex === 0 ? 0 : 1, ',', '.').' '.$units[$unitIndex];
    };
    $resolveDocumentType = static function (mixed $document): string {
        $mimeType = mb_strtolower((string) ($document->file_mime ?? ''));

        return match (true) {
            str_contains($mimeType, 'pdf') => 'PDF',
            str_contains($mimeType, 'word') => 'DOC',
            str_contains($mimeType, 'excel'), str_contains($mimeType, 'sheet') => 'Planilha',
            str_contains($mimeType, 'image') => 'Imagem',
            default => strtoupper(pathinfo((string) ($document->file_original_name ?? $document->file_path ?? ''), PATHINFO_EXTENSION) ?: 'Arquivo'),
        };
    };
@endphp

<div class="mb-8">
    <div class="mb-8 flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <div class="font-jetbrains mb-3 text-[11px] uppercase tracking-[.18em] text-gold-500">ACESSO EXTERNO · BIBLIOTECA</div>
            <h1 class="font-fraunces mb-2 text-[34px] font-medium leading-tight text-navy-900">Biblioteca de Arquivos</h1>
            <p class="font-inter text-[14.5px] text-ink-500">Manuais, políticas e documentos oficiais para consulta e download no mesmo padrão do seu portal.</p>
        </div>
        <div class="font-jetbrains text-[11px] uppercase tracking-[.1em] text-ink-400">
            {{ sprintf('%02d', $generalDocumentsCount) }} públicos · {{ sprintf('%02d', $privateDocumentsCount) }} privados · {{ sprintf('%02d', $categoryCount) }} categorias
        </div>
    </div>

    <div class="mb-8 overflow-hidden rounded-[8px] border border-ink-200 bg-white shadow-portal-subtle">
        <div class="grid grid-cols-1 divide-y divide-ink-100 md:grid-cols-2 md:divide-x md:divide-y-0 xl:grid-cols-4">
            <div class="p-6">
                <div class="font-inter mb-2 text-[11px] font-semibold uppercase tracking-[.12em] text-ink-400">Documentos Gerais</div>
                <div class="font-fraunces text-[28px] font-medium leading-none text-navy-900">{{ sprintf('%02d', $generalDocumentsCount) }}</div>
                <div class="font-inter mt-3 text-[12px] text-ink-500">Acervo institucional visível com os filtros atuais.</div>
            </div>
            <div class="p-6">
                <div class="font-inter mb-2 text-[11px] font-semibold uppercase tracking-[.12em] text-ink-400">Meus Documentos</div>
                <div class="font-fraunces text-[28px] font-medium leading-none text-navy-900">{{ sprintf('%02d', $privateDocumentsCount) }}</div>
                <div class="font-inter mt-3 text-[12px] text-ink-500">Arquivos exclusivos vinculados à sua conta.</div>
            </div>
            <div class="p-6">
                <div class="font-inter mb-2 text-[11px] font-semibold uppercase tracking-[.12em] text-ink-400">Categorias</div>
                <div class="font-fraunces text-[28px] font-medium leading-none text-navy-900">{{ sprintf('%02d', $categoryCount) }}</div>
                <div class="font-inter mt-3 text-[12px] text-ink-500">{{ sprintf('%02d', $cataloguedDocumentsCount) }} arquivos públicos catalogados.</div>
            </div>
            <div class="p-6">
                <div class="font-inter mb-2 text-[11px] font-semibold uppercase tracking-[.12em] text-ink-400">Foco Atual</div>
                <div class="font-fraunces text-[22px] font-medium leading-snug text-navy-900">{{ $selectedCategory?->name ?? 'Acervo completo' }}</div>
                <div class="font-inter mt-3 text-[12px] text-ink-500">
                    @if (filled($term))
                        Busca ativa por "{{ $term }}"
                    @elseif ($activeFiltersCount > 0)
                        Recorte manual aplicado
                    @else
                        Sem filtros ativos no momento
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="row g-8">
        <div class="col-xl-3 col-lg-4">
            <div class="sticky-top" style="top: 2rem; z-index: 10;">
                <div class="doc-sidebar-panel">
                    <div class="mb-6">
                        <div class="font-jetbrains mb-2 text-[11px] uppercase tracking-[.14em] text-ink-400">Busca e classificação</div>
                        <p class="font-inter mb-0 text-[13px] leading-relaxed text-ink-500">Refine o acervo público por palavra-chave e categoria sem sair do visual do portal.</p>
                    </div>

                    <form action="{{ route('nimbus.documents.index') }}" method="get" class="space-y-6">
                        @if (!empty($currentCategory))
                            <input type="hidden" name="category_id" value="{{ $currentCategory }}">
                        @endif

                        <div>
                            <label for="documents-search" class="font-inter mb-3 block text-[11px] font-semibold uppercase tracking-[.12em] text-ink-400">Buscar</label>
                            <div class="relative">
                                <i class="bi bi-search pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-[14px] text-ink-400"></i>
                                <input
                                    id="documents-search"
                                    type="text"
                                    name="q"
                                    value="{{ $term }}"
                                    class="h-[46px] w-full rounded-[6px] border border-ink-200 bg-white ps-11 pe-4 font-inter text-[14px] text-navy-900 shadow-portal-subtle transition-colors placeholder:text-ink-300 focus:border-gold-500 focus:outline-none"
                                    placeholder="Palavras-chave, títulos ou temas"
                                >
                            </div>
                            <button type="submit" class="mt-4 inline-flex h-[40px] w-full items-center justify-center gap-2 rounded-[5px] bg-navy-900 px-4 font-inter text-[12px] font-semibold uppercase tracking-[.1em] text-white transition-colors hover:bg-navy-800">
                                <i class="bi bi-search text-[12px]"></i>
                                Aplicar busca
                            </button>
                        </div>

                        <div>
                            <div class="font-inter mb-4 block text-[11px] font-semibold uppercase tracking-[.12em] text-ink-400">Categorias</div>
                            <div class="flex flex-col gap-1.5">
                                <a
                                    href="{{ route('nimbus.documents.index', ['q' => $term]) }}"
                                    class="doc-filter-link {{ empty($currentCategory) ? 'is-active' : '' }}"
                                >
                                    <span>Todas</span>
                                    @if (empty($currentCategory))
                                        <i class="bi bi-check-lg text-[12px]"></i>
                                    @endif
                                </a>

                                @foreach ($categories as $category)
                                    @php
                                        $isActiveCategory = (string) $currentCategory === (string) $category->id;
                                    @endphp
                                    <a
                                        href="{{ route('nimbus.documents.index', ['category_id' => $category->id, 'q' => $term]) }}"
                                        class="doc-filter-link {{ $isActiveCategory ? 'is-active' : '' }}"
                                    >
                                        <span class="truncate">{{ $category->name }}</span>
                                        @if ($isActiveCategory)
                                            <i class="bi bi-check-lg text-[12px]"></i>
                                        @endif
                                    </a>
                                @endforeach
                            </div>
                        </div>

                        @if ($activeFiltersCount > 0)
                            <div class="rounded-[8px] border border-gold-200 bg-gold-50 p-4">
                                <div class="font-jetbrains text-[10px] uppercase tracking-[.14em] text-gold-700">Filtros ativos</div>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @if (filled($term))
                                        <span class="doc-token">Busca · {{ $term }}</span>
                                    @endif
                                    @if ($selectedCategory)
                                        <span class="doc-token">Categoria · {{ $selectedCategory->name }}</span>
                                    @endif
                                </div>
                            </div>
                        @endif

                        @if ($activeFiltersCount > 0)
                            <a href="{{ route('nimbus.documents.index') }}" class="inline-flex h-[40px] w-full items-center justify-center gap-2 rounded-[5px] border border-ink-200 bg-white px-4 font-inter text-[12px] font-semibold uppercase tracking-[.1em] text-ink-700 no-underline transition-colors hover:bg-ink-50">
                                <i class="bi bi-x-lg text-[11px]"></i>
                                Limpar filtros
                            </a>
                        @endif
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-9 col-lg-8">
            <div class="overflow-hidden rounded-[8px] border border-ink-200 bg-white shadow-portal-subtle">
                <div class="flex flex-col gap-4 border-b border-ink-100 px-7 py-6 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h2 class="font-fraunces mb-1 text-[22px] font-medium text-navy-900">Acervo disponível</h2>
                        <div class="font-jetbrains text-[11px] uppercase tracking-[.1em] text-ink-400">
                            {{ $generalDocumentsCount }} documentos públicos visíveis · {{ $privateDocumentsCount }} arquivos privados na sua área
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="doc-meta-pill">Painel documental</div>
                        @if (filled($term))
                            <div class="doc-meta-pill doc-meta-pill-muted">Busca · {{ $term }}</div>
                        @endif
                        @if ($selectedCategory)
                            <div class="doc-meta-pill doc-meta-pill-muted">Categoria · {{ $selectedCategory->name }}</div>
                        @endif
                    </div>
                </div>

                <div class="px-7 pt-6">
                    <div class="doc-tabs mb-7">
                        <a href="#general" class="doc-tab on" data-doc-tab="general">
                            Documentos Gerais
                            <span class="count-pill">{{ $generalDocumentsCount }}</span>
                        </a>
                        <a href="#private" class="doc-tab" data-doc-tab="private">
                            Meus Documentos
                            <span class="count-pill">{{ $privateDocumentsCount }}</span>
                        </a>
                    </div>
                </div>

                <div class="px-7 pb-7">
                    <div class="tab-content">
                        <div class="tab-pane-custom active" id="general">
                            @if ($documents->isEmpty())
                                <div class="doc-empty-state">
                                    <div class="mb-4">
                                        <i class="bi bi-folder-x text-4xl text-ink-200"></i>
                                    </div>
                                    <h6 class="font-fraunces mb-2 text-lg text-navy-900">Nenhum documento encontrado</h6>
                                    <p class="font-inter mx-auto max-w-sm text-sm text-ink-500">
                                        Não encontramos arquivos com os filtros atuais. Ajuste a busca ou revise a categoria selecionada.
                                    </p>
                                    @if ($activeFiltersCount > 0)
                                        <a href="{{ route('nimbus.documents.index') }}" class="mt-6 inline-flex items-center justify-center gap-2 rounded-[5px] border border-ink-200 bg-white px-5 py-2.5 text-[13px] font-semibold text-ink-700 no-underline transition-colors hover:bg-ink-50">
                                            <span>Voltar ao acervo completo</span>
                                            <i class="bi bi-arrow-right"></i>
                                        </a>
                                    @endif
                                </div>
                            @else
                                <div class="row g-6">
                                    @foreach ($documents as $document)
                                        <div class="col-xl-6">
                                            <div class="doc-card doc-card-general">
                                                <div class="mb-6 flex items-start justify-between gap-4">
                                                    <div class="doc-card-icon">
                                                        <i class="bi bi-file-earmark-text text-[18px]"></i>
                                                    </div>
                                                    <div class="flex flex-wrap items-center justify-end gap-2">
                                                        <div class="doc-scope-pill">Público</div>
                                                        @if ($document->category)
                                                            <div class="badge draft">
                                                                {{ $document->category->name }}
                                                            </div>
                                                        @endif
                                                    </div>
                                                </div>

                                                <div class="mb-6">
                                                    <div class="font-jetbrains mb-3 text-[10px] uppercase tracking-[.14em] text-ink-400">Documento institucional</div>
                                                    <h5 class="font-inter mb-2 text-[17px] font-semibold text-navy-900">
                                                        {{ $document->title }}
                                                    </h5>
                                                    <p class="line-clamp-3 font-inter text-[13px] leading-relaxed text-ink-500">
                                                        {{ $document->description ?? 'Sem descrição disponível.' }}
                                                    </p>
                                                </div>

                                                <div class="doc-card-meta">
                                                    <div>
                                                        <div class="font-jetbrains mb-1 text-[10px] uppercase tracking-[.12em] text-ink-400">Disponível desde</div>
                                                        <div class="font-inter text-[13px] font-medium text-navy-900">
                                                            {{ $document->published_at ? $document->published_at->format('d/m/Y') : ($document->created_at ? $document->created_at->format('d/m/Y') : '-') }}
                                                        </div>
                                                    </div>
                                                    <div class="text-start md:text-right">
                                                        <div class="font-jetbrains mb-1 text-[10px] uppercase tracking-[.12em] text-ink-400">Formato</div>
                                                        <div class="font-inter text-[13px] font-medium text-navy-900">
                                                            {{ $resolveDocumentType($document) }}
                                                        </div>
                                                        <div class="font-inter mt-1 text-[11.5px] text-ink-400">
                                                            {{ $formatFileSize($document->file_size ?? null) }}
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="mt-5 flex items-center justify-between gap-4">
                                                    <button
                                                        type="button"
                                                        class="doc-action-link"
                                                        data-open-preview
                                                        data-preview-url="{{ route('nimbus.documents.general.preview', $document) }}"
                                                        data-download-url="{{ route('nimbus.documents.general.download', $document) }}"
                                                        data-title="{{ $document->title }}"
                                                    >
                                                        <span>Visualizar</span>
                                                        <i class="bi bi-arrow-up-right text-[11px]"></i>
                                                    </button>

                                                    <div class="flex items-center gap-2">
                                                        <a
                                                            href="{{ route('nimbus.documents.general.download', $document) }}"
                                                            class="doc-btn-square"
                                                            title="Baixar"
                                                        >
                                                            <i class="bi bi-download"></i>
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        <div class="tab-pane-custom hidden" id="private">
                            @if ($userDocs->isEmpty())
                                <div class="doc-empty-state">
                                    <div class="mb-4">
                                        <i class="bi bi-person-fill-lock text-4xl text-ink-200"></i>
                                    </div>
                                    <h6 class="font-fraunces mb-2 text-lg text-navy-900">Nenhum documento privado</h6>
                                    <p class="font-inter mx-auto max-w-sm text-sm text-ink-500">Você não possui documentos exclusivos vinculados à sua conta no momento.</p>
                                    <button type="button" class="mt-6 inline-flex items-center justify-center gap-2 rounded-[5px] border border-ink-200 bg-white px-5 py-2.5 text-[13px] font-semibold text-ink-700 transition-colors hover:bg-ink-50" data-switch-doc-tab="general">
                                        <span>Ver documentos gerais</span>
                                        <i class="bi bi-arrow-right"></i>
                                    </button>
                                </div>
                            @else
                                <div class="row g-6">
                                    @foreach ($userDocs as $document)
                                        <div class="col-xl-6">
                                            <div class="doc-card doc-card-private">
                                                <div class="mb-6 flex items-start justify-between gap-4">
                                                    <div class="doc-card-icon">
                                                        <i class="bi bi-shield-lock text-[18px]"></i>
                                                    </div>
                                                    <div class="flex flex-wrap items-center justify-end gap-2">
                                                        <div class="doc-scope-pill doc-scope-pill-private">Reservado</div>
                                                        <div class="badge pending">
                                                            <div class="badge-pulse"></div>
                                                            Exclusivo
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="mb-6">
                                                    <div class="font-jetbrains mb-3 text-[10px] uppercase tracking-[.14em] text-ink-400">Documento da sua conta</div>
                                                    <h5 class="font-inter mb-2 text-[17px] font-semibold text-navy-900">
                                                        {{ $document->title }}
                                                    </h5>
                                                    <p class="line-clamp-3 font-inter text-[13px] leading-relaxed text-ink-500">
                                                        {{ $document->description ?? 'Documento privado disponível para a sua área.' }}
                                                    </p>
                                                </div>

                                                <div class="doc-card-meta">
                                                    <div>
                                                        <div class="font-jetbrains mb-1 text-[10px] uppercase tracking-[.12em] text-ink-400">Liberado em</div>
                                                        <div class="font-inter text-[13px] font-medium text-navy-900">
                                                            {{ $document->created_at ? $document->created_at->format('d/m/Y') : '-' }}
                                                        </div>
                                                    </div>
                                                    <div class="text-start md:text-right">
                                                        <div class="font-jetbrains mb-1 text-[10px] uppercase tracking-[.12em] text-ink-400">Formato</div>
                                                        <div class="font-inter text-[13px] font-medium text-navy-900">
                                                            {{ $resolveDocumentType($document) }}
                                                        </div>
                                                        <div class="font-inter mt-1 text-[11.5px] text-ink-400">
                                                            {{ $formatFileSize($document->file_size ?? null) }}
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="mt-5 flex items-center justify-between gap-4">
                                                    <button
                                                        type="button"
                                                        class="doc-action-link"
                                                        data-open-preview
                                                        data-preview-url="{{ route('nimbus.documents.preview', $document) }}"
                                                        data-download-url="{{ route('nimbus.documents.download', $document) }}"
                                                        data-title="{{ $document->title }}"
                                                    >
                                                        <span>Visualizar</span>
                                                        <i class="bi bi-arrow-up-right text-[11px]"></i>
                                                    </button>

                                                    <div class="flex items-center gap-2">
                                                        <a
                                                            href="{{ route('nimbus.documents.download', $document) }}"
                                                            class="doc-btn-square"
                                                            title="Baixar"
                                                        >
                                                            <i class="bi bi-download"></i>
                                                        </a>
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
        </div>
    </div>
</div>

<div class="modal fade" id="previewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable" style="height: 90vh;">
        <div class="modal-content h-100 overflow-hidden rounded-[8px] border-0 shadow-lg">
            <div class="modal-header flex items-center justify-between border-bottom bg-white px-6 py-4">
                <div class="flex items-center gap-3">
                    <div class="flex h-8 w-8 items-center justify-center rounded-[4px] bg-ink-50 text-navy-700">
                        <i class="bi bi-file-earmark-text"></i>
                    </div>
                    <h5 class="font-inter mb-0 text-[15px] font-semibold text-navy-900" id="previewModalLabel">Visualizar Documento</h5>
                </div>
                <div class="flex items-center gap-3">
                    <a href="#" id="downloadBtn" class="flex h-[34px] items-center justify-center rounded-[4px] bg-navy-900 px-4 text-[12px] font-semibold text-white no-underline transition-all hover:bg-navy-800" download>
                        <i class="bi bi-download me-2"></i> Baixar
                    </a>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            <div class="modal-body relative bg-ink-50 p-0">
                <div id="loader" class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 text-center">
                    <div class="spinner-border mb-3 text-navy-700" role="status"></div>
                    <div class="font-inter text-[13px] text-ink-400">Carregando visualização...</div>
                </div>
                <iframe src="" id="previewFrame" class="h-full w-full border-0"></iframe>
            </div>
        </div>
    </div>
</div>

<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
function openPreview(previewUrl, downloadUrl, title) {
    const modal = new bootstrap.Modal(document.getElementById('previewModal'));
    const frame = document.getElementById('previewFrame');
    const label = document.getElementById('previewModalLabel');
    const download = document.getElementById('downloadBtn');
    const loader = document.getElementById('loader');

    frame.src = 'about:blank';
    loader.style.display = 'block';
    label.textContent = title;
    frame.src = previewUrl;
    download.href = downloadUrl;
    modal.show();
}

document.getElementById('previewFrame')?.addEventListener('load', () => {
    const loader = document.getElementById('loader');

    if (loader) {
        loader.style.display = 'none';
    }
});

document.querySelectorAll('[data-open-preview]').forEach((trigger) => {
    trigger.addEventListener('click', () => {
        openPreview(trigger.dataset.previewUrl, trigger.dataset.downloadUrl, trigger.dataset.title);
    });
});

const documentTabs = document.querySelectorAll('[data-doc-tab]');
const documentPanes = document.querySelectorAll('.tab-pane-custom');
const validDocumentTabs = new Set(['general', 'private']);

function switchDocumentTab(targetId, shouldSyncHash = true) {
    if (! validDocumentTabs.has(targetId)) {
        return;
    }

    documentTabs.forEach((tab) => {
        tab.classList.toggle('on', tab.dataset.docTab === targetId);
    });

    documentPanes.forEach((pane) => {
        pane.classList.toggle('hidden', pane.id !== targetId);
        pane.classList.toggle('active', pane.id === targetId);
    });

    if (shouldSyncHash) {
        window.location.hash = targetId;
    }
}

documentTabs.forEach((tab) => {
    tab.addEventListener('click', (event) => {
        event.preventDefault();
        switchDocumentTab(tab.dataset.docTab);
    });
});

document.querySelectorAll('[data-switch-doc-tab]').forEach((trigger) => {
    trigger.addEventListener('click', () => {
        switchDocumentTab(trigger.dataset.switchDocTab);
    });
});

const initialDocumentTab = window.location.hash.replace('#', '');

if (validDocumentTabs.has(initialDocumentTab)) {
    switchDocumentTab(initialDocumentTab, false);
}
</script>
@endsection

@push('styles')
<style>
    .doc-sidebar-panel {
        border: 1px solid var(--color-ink-200);
        background: #fff;
        border-radius: 8px;
        padding: 28px 24px;
        box-shadow: var(--shadow-portal-subtle);
    }

    .doc-filter-link {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 11px 14px;
        border-radius: 5px;
        color: var(--color-ink-500);
        font: 500 13.5px/1.35 var(--font-inter);
        text-decoration: none;
        transition: all 0.2s ease;
    }

    .doc-filter-link:hover {
        background: var(--color-ink-50);
        color: var(--color-navy-900);
    }

    .doc-filter-link.is-active {
        background: var(--color-navy-50);
        color: var(--color-navy-700);
        border: 1px solid rgba(31, 63, 117, 0.16);
    }

    .doc-meta-pill,
    .doc-token {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        min-height: 28px;
        padding: 0 12px;
        border-radius: 999px;
        border: 1px solid rgba(31, 63, 117, 0.12);
        background: rgba(242, 245, 250, 0.92);
        color: var(--color-navy-700);
        font: 500 11px/1 var(--font-jetbrains);
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .doc-meta-pill-muted {
        color: var(--color-ink-500);
        background: #fff;
        border-color: var(--color-ink-200);
    }

    .doc-token {
        border-color: rgba(165, 132, 50, 0.18);
        background: #fff;
        color: var(--color-gold-700);
    }

    .doc-tabs {
        display: inline-flex;
        flex-wrap: wrap;
        gap: 4px;
        width: fit-content;
        padding: 4px;
        border: 1px solid var(--color-ink-200);
        border-radius: 6px;
        background: var(--color-ink-50);
    }

    .doc-tab {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        min-height: 34px;
        padding: 0 14px;
        border-radius: 4px;
        color: var(--color-ink-500);
        font: 500 13px/1 var(--font-inter);
        text-decoration: none;
        transition: all 0.2s ease;
    }

    .doc-tab.on {
        background: #fff;
        color: var(--color-navy-900);
        box-shadow: 0 1px 2px rgba(11, 27, 54, 0.06);
    }

    .count-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 20px;
        height: 18px;
        padding: 0 6px;
        border-radius: 10px;
        background: #e8ebf1;
        color: var(--color-ink-500);
        font: 500 10px/1 var(--font-jetbrains);
    }

    .doc-tab.on .count-pill {
        background: var(--color-navy-50);
        color: var(--color-navy-700);
    }

    .doc-card {
        position: relative;
        display: flex;
        flex-direction: column;
        height: 100%;
        padding: 24px;
        border: 1px solid var(--color-ink-200);
        border-radius: 8px;
        background: #fff;
        box-shadow: var(--shadow-portal-subtle);
        overflow: hidden;
        transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .doc-card::before {
        content: "";
        position: absolute;
        inset: 0 0 auto 0;
        height: 2px;
        background: linear-gradient(90deg, rgba(184, 150, 74, 0.92), rgba(184, 150, 74, 0.08));
    }

    .doc-card-private::before {
        background: linear-gradient(90deg, rgba(184, 150, 74, 0.92), rgba(31, 63, 117, 0.12));
    }

    .doc-card:hover {
        transform: translateY(-1px);
        border-color: rgba(165, 132, 50, 0.24);
        box-shadow: 0 18px 40px rgba(11, 27, 54, 0.08);
    }

    .doc-card-icon {
        display: flex;
        width: 42px;
        height: 42px;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        background: var(--color-ink-50);
        color: var(--color-navy-700);
    }

    .doc-scope-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 24px;
        padding: 0 10px;
        border-radius: 999px;
        background: var(--color-navy-50);
        color: var(--color-navy-700);
        font: 600 10px/1 var(--font-jetbrains);
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .doc-scope-pill-private {
        background: var(--color-gold-50);
        color: var(--color-gold-700);
    }

    .doc-card-meta {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px;
        padding-top: 18px;
        border-top: 1px solid var(--color-ink-100);
    }

    .doc-action-link {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 0;
        border: 0;
        background: transparent;
        color: var(--color-navy-700);
        font: 600 13px/1 var(--font-inter);
        transition: color 0.2s ease, text-decoration-color 0.2s ease;
        text-decoration: underline;
        text-decoration-color: transparent;
        text-underline-offset: 3px;
    }

    .doc-action-link:hover {
        color: var(--color-navy-900);
        text-decoration-color: var(--color-gold-500);
    }

    .doc-btn-square {
        display: inline-flex;
        width: 34px;
        height: 34px;
        align-items: center;
        justify-content: center;
        border: 1px solid var(--color-ink-200);
        border-radius: 5px;
        background: #fff;
        color: var(--color-ink-700);
        text-decoration: none;
        transition: all 0.2s ease;
    }

    .doc-btn-square:hover {
        border-color: rgba(165, 132, 50, 0.26);
        background: var(--color-gold-50);
        color: var(--color-navy-900);
    }

    .doc-empty-state {
        padding: 56px 24px;
        border: 1px dashed var(--color-ink-200);
        border-radius: 8px;
        background: linear-gradient(180deg, rgba(245, 247, 251, 0.6), rgba(255, 255, 255, 0.92));
        text-align: center;
    }

    .badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 5px 11px 5px 9px;
        border: 1px solid transparent;
        border-radius: 4px;
        font: 600 11.5px/1 var(--font-inter);
        letter-spacing: 0.06em;
        text-transform: uppercase;
    }

    .badge.draft {
        background: #f5f7fb;
        color: #5a6478;
        border-color: #d4d9e2;
    }

    .badge.pending {
        background: #fbf1dd;
        color: #946420;
        border-color: rgba(148, 100, 32, 0.18);
    }

    .tab-pane-custom.hidden {
        display: none;
    }

    .line-clamp-3 {
        display: -webkit-box;
        overflow: hidden;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 3;
    }

    @media (max-width: 767.98px) {
        .doc-card-meta {
            grid-template-columns: 1fr;
        }
    }
</style>
@endpush
