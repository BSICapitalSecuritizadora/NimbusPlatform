@extends('site.layout')

@section('title', 'Incorporação — BSI Capital')

@section('content')
<section class="py-5 bg-light-subtle" style="background: #f8f9fa; min-height: 100vh;">
    <div class="container py-5 text-center">
        <h1 class="h2 fw-bold text-brand mb-4" style="color: var(--brand);">Incorporação</h1>
        <p class="text-muted lead mx-auto" style="max-width: 600px;">
            Viabilizamos a captação de recursos para projetos de incorporação imobiliária com máxima eficiência.
        </p>
        <div class="mt-5">
            <a href="{{ route('site.contact') }}" class="btn btn-brand px-4 py-2">Falar com um especialista</a>
        </div>
    </div>
</section>
@endsection
