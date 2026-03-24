@extends('site.layout')

@section('title', 'CRI / Real Estate — BSI Capital')

@section('content')
<section class="py-5 bg-light-subtle" style="background: #f8f9fa; min-height: 100vh;">
    <div class="container py-5 text-center">
        <h1 class="h2 fw-bold text-brand mb-4" style="color: var(--brand);">CRI / Real Estate</h1>
        <p class="text-muted lead mx-auto" style="max-width: 600px;">
            Operações lastreadas em recebíveis imobiliários, oferecendo estruturação completa para o mercado de Real Estate.
        </p>
        <div class="mt-5">
            <a href="{{ route('site.contact') }}" class="btn btn-brand px-4 py-2">Falar com um especialista</a>
        </div>
    </div>
</section>
@endsection
