@extends('portal.layout')

@section('content')
<div class="container">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h1 class="h4 mb-0">Documentos</h1>

        <div>
            @if($newCount > 0)
                <span class="badge bg-primary">Novos desde o último acesso: {{ $newCount }}</span>
            @else
                <span class="badge bg-secondary">Nenhum novo documento</span>
            @endif
        </div>
    </div>

    <form method="GET" class="card p-3 mb-4">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Buscar por título</label>
                <input type="text" name="q" class="form-control" value="{{ $search }}" placeholder="Ex.: Relatório Anual">
            </div>

            <div class="col-md-3">
                <label class="form-label">Categoria</label>
                <select name="category" class="form-select">
                    <option value="">Todas</option>
                    @foreach($categories as $key => $label)
                        <option value="{{ $key }}" @selected($category === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label">Emissão / Série</label>
                <select name="emission_id" class="form-select">
                    <option value="">Todas</option>
                    @foreach($emissions as $e)
                        <option value="{{ $e->id }}" @selected((string)$emissionId === (string)$e->id)>{{ $e->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-2 d-flex align-items-end">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="only_new" value="1" id="onlyNew" @checked($onlyNew)>
                    <label class="form-check-label" for="onlyNew">
                        Somente novos
                    </label>
                </div>
            </div>

            <div class="col-md-3">
                <label class="form-label">De</label>
                <input type="date" name="from" class="form-control" value="{{ $dateFrom }}">
            </div>

            <div class="col-md-3">
                <label class="form-label">Até</label>
                <input type="date" name="to" class="form-control" value="{{ $dateTo }}">
            </div>

            <div class="col-md-6 d-flex align-items-end gap-2">
                <button class="btn btn-primary">Filtrar</button>
                <a class="btn btn-outline-secondary" href="{{ route('portal.documents.index') }}">Limpar</a>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="list-group list-group-flush">
            @forelse($documents as $doc)
                @php
                    $docDate = $doc->{$dateField} ?? $doc->created_at;
                    $isNew = $docDate && ($docDate > ($request->user('investor')->last_portal_seen_at ?? now()->subYears(50)));
                @endphp

                <div class="list-group-item d-flex justify-content-between align-items-start">
                    <div>
                        <div class="d-flex gap-2 align-items-center">
                            <strong>{{ $doc->title }}</strong>
                            @if($isNew)
                                <span class="badge bg-success">Novo</span>
                            @endif
                        </div>

                        <div class="text-muted small">
                            Categoria: {{ $categories[$doc->category] ?? $doc->category ?? '-' }}
                            • Data: {{ optional($docDate)->format('d/m/Y') ?? '-' }}
                        </div>

                        @if($doc->emissions()->exists())
                            <div class="small mt-1">
                                Séries:
                                @foreach($doc->emissions as $em)
                                    <span class="badge bg-light text-dark border">{{ $em->name }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="text-end">
                        <a class="btn btn-sm btn-outline-primary"
                           href="{{ route('portal.documents.download', $doc) }}">
                           Baixar
                        </a>
                    </div>
                </div>
            @empty
                <div class="list-group-item text-muted">Nenhum documento encontrado com os filtros atuais.</div>
            @endforelse
        </div>
    </div>

    <div class="mt-3">
        {{ $documents->links() }}
    </div>
</div>
@endsection