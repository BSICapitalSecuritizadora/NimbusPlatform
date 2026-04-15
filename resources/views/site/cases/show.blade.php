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
                        <div class="kicker mb-3" style="text-transform: uppercase; letter-spacing: 0.15em; font-weight: 700; color: var(--gold); font-size: 0.85rem;">{{ $case->kicker }}</div>
                        <h1 class="display-5 fw-bold mb-4" style="color: var(--brand); letter-spacing: -0.01em;">{{ $case->title }}</h1>
                        <div class="d-flex flex-wrap gap-2 mb-4">
                            @foreach($case->badges as $badge)
                                <span class="badge rounded-pill px-3 py-2" style="background: rgba(0,32,91,0.05); color: var(--brand); font-weight: 600; font-size: 0.8rem;">{{ $badge }}</span>
                            @endforeach
                        </div>
                        <p class="lead text-muted mb-0" style="font-size: 1.25rem; line-height: 1.6;">{{ $case->description }}</p>
                    </div>
                    <div class="col-lg-5">
                        <div class="overflow-hidden shadow-lg" style="border-radius: 40px; border: 4px solid white;">
                            <img src="{{ $case->image }}" class="img-fluid w-100 object-fit-cover" style="height: 380px;" alt="{{ $case->title }}">
                        </div>
                    </div>
                </div>

                @if(isset($case->highlights))
                <div class="row g-3 mb-5">
                    @foreach($case->highlights as $stat)
                    <div class="col-6 col-md-3">
                        <div class="p-4 text-center h-100" style="background: white; border: 1px solid rgba(0,32,91,0.08); border-radius: 24px; transition: .3s;" onmouseover="this.style.borderColor='var(--gold)'; this.style.transform='translateY(-5px)'" onmouseout="this.style.borderColor='rgba(0,32,91,0.08)'; this.style.transform='translateY(0)'">
                            <div class="text-muted small text-uppercase fw-bold mb-2" style="letter-spacing: 0.05em; font-size: 0.7rem;">{{ $stat['label'] }}</div>
                            <div class="h3 fw-bold mb-0" style="color: var(--brand);">{{ $stat['value'] }}</div>
                        </div>
                    </div>
                    @endforeach
                </div>
                @endif

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
