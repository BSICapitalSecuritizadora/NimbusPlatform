<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>
        @isset($title)
            {{ $title }}
        @else
            @yield('title', 'BSI Capital')
        @endisset
    </title>
    <link rel="icon" type="image/jpeg" href="{{ asset('favicon.jpg') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">

    @include('site.partials.styles')

    @stack('head')

    @if(config('services.clarity.id'))
    <script type="text/javascript" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        (function(c,l,a,r,i,t,y){
            c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
            t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;
            y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
        })(window, document, "clarity", "script", "{{ config('services.clarity.id') }}");
    </script>
    @endif
</head>
<body>
@php
    $portalUrl = env('APP_PORTAL_URL', '/portal');
@endphp

@include('site.partials.navbar')

<main class="site-main">
    @isset($slot)
        {{ $slot }}
    @else
        @yield('content')
    @endisset
</main>

@include('site.partials.footer')

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

@hasSection('uses_flux')
    @fluxScripts(['nonce' => \Illuminate\Support\Facades\Vite::cspNonce()])
@endif
@stack('scripts')
</body>
</html>
