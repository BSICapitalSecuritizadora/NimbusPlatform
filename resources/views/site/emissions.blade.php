@extends('site.layout')
@section('title','Emissões — BSI Capital')

@section('content')
<section class="py-5">
  <div class="container">
    <div class="d-flex align-items-end justify-content-between mb-3">
      <div>
        <div class="kicker mb-2">Emissões</div>
        <h1 class="h3 fw-bold mb-1">Emissões públicas</h1>
        <div class="text-muted small">Listagem das emissões marcadas como públicas.</div>
      </div>
    </div>

    <div class="row g-3">
      @forelse($emissions as $e)
        <div class="col-md-6 col-lg-4">
          <div class="card p-4 h-100">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <div>
                <div class="fw-semibold">{{ $e->name }}</div>
                <div class="text-muted small">{{ $e->type ?? '—' }} • {{ $e->status ?? '—' }}</div>
              </div>
              <span class="badge badge-soft">{{ $e->type ?? '—' }}</span>
            </div>

            <div class="text-muted small">
              Emissor: {{ $e->issuer ?? '—' }}<br>
              Vencimento: {{ optional($e->maturity_date)->format('d/m/Y') ?? '—' }}
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