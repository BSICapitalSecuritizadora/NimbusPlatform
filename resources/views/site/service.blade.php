@extends('site.layout')
@section('title','Serviços — BSI Capital')

@section('content')
<section class="py-5">
  <div class="container">
    <div class="kicker mb-2">Serviços</div>
    <h1 class="h3 fw-bold mb-3">Soluções com padrão bancário</h1>
    <p class="text-muted mb-4">Página MVP (conteúdo provisório). Em seguida refinamos texto e layout.</p>

    <div class="row g-3">
      @foreach([
        ['Estruturação','Modelagem, documentação e governança.'],
        ['Gestão','Acompanhamento e transparência ao investidor.'],
        ['Tecnologia','Portal, ACL, auditoria e automação.'],
      ] as [$t,$d])
        <div class="col-md-4">
          <div class="card p-4 h-100">
            <div class="fw-semibold">{{ $t }}</div>
            <div class="text-muted small mt-1">{{ $d }}</div>
          </div>
        </div>
      @endforeach
    </div>
  </div>
</section>
@endsection