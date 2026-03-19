@extends('site.layout')
@section('title','Contato — BSI Capital')

@section('content')
<section class="py-5">
  <div class="container">
    <div class="kicker mb-2">Contato</div>
    <h1 class="h3 fw-bold mb-3">Fale com a BSI</h1>
    <p class="text-muted mb-4">Página MVP. Depois conectamos a um formulário real e e-mail.</p>

    <div class="row g-3">
      <div class="col-lg-6">
        <div class="card p-4">
          <div class="fw-semibold mb-2">Informações</div>
          <div class="text-muted small">E-mail: contato@bsicapital.com.br</div>
          <div class="text-muted small">Telefone: (11) 0000-0000</div>
          <div class="text-muted small">Endereço: São Paulo — SP</div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card p-4">
          <div class="fw-semibold mb-2">Mensagem</div>
          <div class="text-muted small mb-3">Formulário MVP (sem envio).</div>
          <input class="form-control mb-2" placeholder="Nome">
          <input class="form-control mb-2" placeholder="E-mail">
          <textarea class="form-control mb-3" rows="4" placeholder="Mensagem"></textarea>
          <button class="btn btn-brand" type="button">Enviar</button>
        </div>
      </div>
    </div>
  </div>
</section>
@endsection