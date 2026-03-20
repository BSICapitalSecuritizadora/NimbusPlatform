@extends('site.layout')
@section('title','Emissões — BSI Capital')

@section('content')
<section class="py-5">
  <div class="container">
    <div class="d-flex flex-column flex-md-row align-items-md-end justify-content-between mb-4 gap-3">
      <div>
        <div class="kicker mb-2">Emissões</div>
        <h1 class="h3 fw-bold mb-1">Emissões públicas</h1>
        <div class="text-muted small">Listagem das emissões marcadas como públicas.</div>
      </div>

      <form method="GET" class="d-flex gap-2" style="max-width: 400px; width: 100%;">
          <input class="form-control rounded-pill border-0 bg-white shadow-sm ps-3" 
                 name="q" 
                 value="{{ $q ?? '' }}" 
                 placeholder="Buscar emissão ou emissor..." 
                 style="border: 1px solid var(--border) !important;">
          <button class="btn btn-brand rounded-pill px-4">Buscar</button>
      </form>
    </div>

    <div class="row g-4">
      @forelse($emissions as $e)
        <div class="col-md-6 col-lg-4">
          <div class="card p-4 h-100 border-0 shadow-sm card-hover" style="border-radius: 32px; transition: transform 0.3s ease, box-shadow 0.3s ease;">
            <div class="d-flex justify-content-between align-items-start mb-3">
              <div>
                <h3 class="h6 fw-bold mb-1" style="color: var(--brand-outline);">{{ $e->name }}</h3>
                <div class="text-muted" style="font-size: 0.75rem;">{{ $e->type ?? '—' }} • {{ $e->status ?? '—' }}</div>
              </div>
              <span class="badge badge-soft px-3 py-2 rounded-pill">{{ $e->type ?? '—' }}</span>
            </div>

            <div class="text-muted border-top pt-3" style="font-size: 0.85rem;">
              <div class="d-flex justify-content-between mb-1">
                <span>Emissor:</span>
                <span class="fw-medium text-body">{{ $e->issuer ?? '—' }}</span>
              </div>
              <div class="d-flex justify-content-between">
                <span>Vencimento:</span>
                <span class="fw-medium text-body">{{ optional($e->maturity_date)->format('d/m/Y') ?? '—' }}</span>
              </div>
            </div>
          </div>
        </div>
      @empty
        <div class="col-12">
          <div class="card p-4 text-muted">Nenhuma emissão pública cadastrada ainda.</div>
        </div>
      @endforelse
    </div>

    <div class="mt-3">
      {{ $emissions->links() }}
    </div>
  </div>
</section>
@endsection