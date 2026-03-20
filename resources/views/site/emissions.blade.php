@extends('site.layout')
@section('title','Emissões — BSI Capital')

@section('content')
<section class="py-5 bg-light-subtle" style="background: #f8f9fa; min-height: 100vh;">
  <div class="container">
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-5 gap-4">
      <div>
        <h1 class="h2 fw-bold mb-1" style="color: #1a1a1a;">Pesquisar emissões</h1>
      </div>

      <form method="GET" class="d-flex gap-2 align-items-center" style="max-width: 500px; width: 100%;">
          <div class="input-group bg-white rounded-pill shadow-sm overflow-hidden" style="border: 1px solid #e0e0e0;">
              <span class="input-group-text border-0 bg-transparent ps-3">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
              </span>
              <input class="form-control border-0 bg-transparent ps-0 py-2" 
                     name="q" 
                     value="{{ $q ?? '' }}" 
                     placeholder="Pesquisar emissões"
                     style="box-shadow: none;">
          </div>
          <button class="btn btn-brand rounded-pill px-4" style="background: var(--brand); color: white; border: none; height: 46px;">Filtrar</button>
      </form>
    </div>

    <div class="row g-4">
      @forelse($emissions as $e)
        <div class="col-md-6 col-lg-4">
          <div class="card h-100 border-0 shadow-sm" style="border-radius: 12px; overflow: hidden; background: white;">
            <div class="card-body p-4">
              <div class="d-flex justify-content-between align-items-start mb-4">
                <div style="max-width: 80%;">
                   <div class="text-muted small fw-bold mb-1">{{ $e->if_code ?? 'CRI' }}</div>
                   <h3 class="h6 fw-bold mb-0 text-dark" style="line-height: 1.4;">{{ $e->name }}</h3>
                </div>
                <div class="bg-light rounded d-flex align-items-center justify-content-center p-2" style="min-width: 60px; height: 40px; color: var(--brand);">
                   @if($e->logo_path)
                     <img src="{{ Storage::url($e->logo_path) }}" alt="{{ $e->name }}" style="max-height: 100%; max-width: 100%; object-fit: contain;">
                   @else
                     @if($e->type === 'CRI')
                       <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M9 8h1m4 0h1m-5 4h1m4 0h1M9 16h1m4 0h1M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"></path></svg>
                     @elseif($e->type === 'CRA')
                       <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"></path></svg>
                     @else
                       <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                     @endif
                   @endif
                </div>
              </div>

              <div class="small">
                <div class="d-flex justify-content-between py-2 border-bottom">
                  <span class="text-muted">Emissão</span>
                  <span class="fw-bold">{{ $e->emission_number ?? '—' }}</span>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                  <span class="text-muted">Série</span>
                  <span class="fw-bold">{{ $e->series ?? '—' }}</span>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                  <span class="text-muted">Data de Emiss.</span>
                  <span class="fw-bold">{{ optional($e->issue_date)->format('d/m/Y') ?? '—' }}</span>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                  <span class="text-muted">Data de Venc.</span>
                  <span class="fw-bold">{{ optional($e->maturity_date)->format('d/m/Y') ?? '—' }}</span>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                  <span class="text-muted">Remuneração</span>
                  <span class="fw-bold text-end ms-3">{{ $e->remuneration ?? '—' }}</span>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                  <span class="text-muted">Oferta</span>
                  <span class="fw-bold">{{ $e->offer_type ?? '—' }}</span>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                  <span class="text-muted">Emissor</span>
                  <span class="fw-bold text-end ms-3">{{ $e->issuer ?? '—' }}</span>
                </div>
                <div class="d-flex justify-content-between py-2">
                  <span class="text-muted">Código ISIN</span>
                  <span class="fw-bold">{{ $e->isin_code ?? '—' }}</span>
                </div>
              </div>
            </div>
            <div class="card-footer bg-transparent border-0 p-4 pt-0">
               <a href="#" class="btn btn-outline-brand w-100 rounded-pill py-2 small fw-bold">Ver detalhes</a>
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