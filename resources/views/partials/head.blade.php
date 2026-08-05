<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

{{-- Authentication pages must never be indexed: robots.txt only asks crawlers not
     to fetch them, it does not prevent indexing through external links. --}}
@if(request()->routeIs([
    'login',
    'investor.login',
    'password.confirm',
    'two-factor.login',
    'verification.notice',
    'pending-approval',
]))
<meta name="robots" content="noindex,nofollow">
@endif

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

@php($cspNonce = \Illuminate\Support\Facades\Vite::cspNonce())

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance(['nonce' => $cspNonce])

@include('partials.clarity')
