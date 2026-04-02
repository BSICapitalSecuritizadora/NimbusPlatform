<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? trim($__env->yieldContent('title', 'Portal do Investidor')) }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gray-50 text-gray-900">
    <header class="border-b bg-white">
        <div class="mx-auto flex max-w-5xl items-center justify-between px-4 py-4">
            <div class="font-semibold">Portal do Investidor</div>
            @auth('investor')
                <div class="flex items-center gap-3">
                    <span class="text-sm text-gray-600">{{ auth('investor')->user()->name }}</span>
                    <form method="POST" action="{{ route('investor.logout') }}">
                        @csrf
                        <button class="rounded bg-gray-900 px-3 py-2 text-sm text-white">Sair</button>
                    </form>
                </div>
            @endauth
        </div>
        @auth('investor')
            <nav class="mx-auto flex max-w-5xl gap-4 px-4 pb-4 text-sm">
                <a class="underline" href="{{ route('investor.dashboard') }}">Inicio</a>
                <a class="underline" href="{{ route('investor.emissions') }}">Minhas Emissoes</a>
                <a class="underline" href="{{ route('investor.documents') }}">Meus Documentos</a>
            </nav>
        @endauth
    </header>

    <main class="mx-auto max-w-5xl px-4 py-6">
        @hasSection('content')
            @yield('content')
        @else
            {{ $slot ?? '' }}
        @endif
    </main>
</body>
</html>
