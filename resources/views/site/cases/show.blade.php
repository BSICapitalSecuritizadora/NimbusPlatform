@extends('site.layout')
@section('title', $case->title . ' — BSI Capital')

@section('content')
<section class="py-5 bg-white">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <nav aria-label="breadcrumb" class="mb-4">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="/" class="text-decoration-none" style="color: var(--brand);">Home</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Estudo de Caso</li>
                    </ol>
                </nav>

                <div class="row align-items-center g-5 mb-5">
                    <div class="col-lg-7">
                        <div class="kicker mb-3">{{ $case->kicker }}</div>
                        <h1 class="display-5 fw-bold mb-4" style="color: var(--brand);">{{ $case->title }}</h1>
                        <div class="d-flex flex-wrap gap-2 mb-4">
                            @foreach($case->badges as $badge)
                                <span class="badge rounded-pill px-3 py-2" style="background: rgba(0,32,91,0.05); color: var(--brand); font-weight: 500;">{{ $badge }}</span>
                            @endforeach
                        </div>
                        <p class="lead text-muted">{{ $case->description }}</p>
                    </div>
                    <div class="col-lg-5">
                        <div class="overflow-hidden shadow-lg" style="border-radius: 32px;">
                            <img src="{{ $case->image }}" class="img-fluid w-100 object-fit-cover" style="height: 400px;" alt="{{ $case->title }}">
                        </div>
                    </div>
                </div>

                <hr class="my-5 opacity-10">

                <div class="row">
                    <div class="col-lg-8 case-content">
                        {!! $case->content !!}
                    </div>
                    <div class="col-lg-4">
                        <div class="card border-0 p-4 sticky-top" style="top: 100px; border-radius: 24px; background: var(--bg);">
                            <h4 class="fw-bold mb-3">Interessado nesta solução?</h4>
                            <p class="text-muted small mb-4">Nossa equipe especialista está pronta para ajudar na estruturação da sua operação.</p>
                            <a href="{{ route('site.contact') }}" class="btn btn-brand w-100 rounded-pill py-2">Fale conosco</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<style>
    .case-content h3 {
        color: var(--brand);
        font-weight: 700;
        margin-top: 2rem;
        margin-bottom: 1rem;
    }
    .case-content p {
        color: #4b5563;
        line-height: 1.8;
        margin-bottom: 1.5rem;
    }
    .case-content ul {
        margin-bottom: 1.5rem;
    }
    .case-content li {
        margin-bottom: 0.5rem;
        color: #4b5563;
    }
</style>
@endsection
