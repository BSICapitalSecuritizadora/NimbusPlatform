@extends('investor.layout')

@section('title', 'Login - Portal do Investidor')

@section('content')
<div class="max-w-md mx-auto bg-white border rounded p-6">
    <h1 class="text-xl font-semibold mb-4">Entrar</h1>

    @if ($errors->any())
        <div class="mb-4 p-3 rounded bg-red-50 text-red-700 text-sm">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('investor.login.post') }}" class="space-y-4">
        @csrf

        <div>
            <label class="block text-sm mb-1">E-mail</label>
            <input name="email" type="email" class="w-full border rounded px-3 py-2" required />
        </div>

        <div>
            <label class="block text-sm mb-1">Senha</label>
            <input name="password" type="password" class="w-full border rounded px-3 py-2" required />
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="remember" />
            Manter conectado
        </label>

        <button class="w-full px-4 py-2 rounded bg-gray-900 text-white">Entrar</button>
    </form>
</div>
@endsection