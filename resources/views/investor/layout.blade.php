<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Portal do Investidor')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gray-50 text-gray-900">
    <header class="border-b bg-white">
        <div class="mx-auto max-w-5xl px-4 py-4 flex items-center justify-between">
            <div class="font-semibold">Portal do Investidor</div>
            @auth('investor')
                <div class="flex items-center gap-3">
                    <span class="text-sm text-gray-600">{{ auth('investor')->user()->name }}</span>
                    <form method="POST" action="{{ route('investor.logout') }}">
                        @csrf
                        <button class="text-sm px-3 py-2 rounded bg-gray-900 text-white">Sair</button>
                    </form>
                </div>
            @endauth
        </div>
        @auth('investor')
            <nav class="mx-auto max-w-5xl px-4 pb-4 flex gap-4 text-sm">
                <a class="underline" href="{{ route('investor.dashboard') }}">Início</a>
                <a class="underline" href="{{ route('investor.emissions') }}">Minhas Emissões</a>
                <a class="underline" href="{{ route('investor.documents') }}">Meus Documentos</a>
            </nav>
        @endauth
    </header>

    <main class="mx-auto max-w-5xl px-4 py-6">
        @yield('content')
    </main>
</body>
</html>