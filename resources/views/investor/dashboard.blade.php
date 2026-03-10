@extends('investor.layout')

@section('title', 'Início - Portal do Investidor')

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-zinc-900 dark:text-zinc-100">Bem-vindo, {{ $investor->name }}</h1>
            <p class="mt-1 text-sm text-zinc-500">Acesse suas emissões e documentos pelo menu acima.</p>
        </div>
    </div>

    @if($newDocsCount > 0)
        <flux:card class="bg-blue-50/50 border-blue-100 dark:bg-blue-900/10 dark:border-blue-800">
            <div class="flex items-start gap-4">
                <div class="mt-1">
                    <flux:icon.document-text class="h-6 w-6 text-blue-600 dark:text-blue-400" />
                </div>
                <div>
                    <h3 class="text-sm font-medium text-blue-900 dark:text-blue-200">Novos documentos disponíveis</h3>
                    <p class="mt-1 text-sm text-blue-700 dark:text-blue-300">
                        Você tem <strong>{{ $newDocsCount }}</strong> documento(s) publicado(s) desde o seu último acesso ao portal.
                    </p>
                    <div class="mt-4">
                         <flux:button size="sm" variant="primary" as="a" href="{{ route('investor.documents') }}">
                            Ver documentos
                        </flux:button>
                    </div>
                </div>
            </div>
        </flux:card>
    @endif
</div>
@endsection