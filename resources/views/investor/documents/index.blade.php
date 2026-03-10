@extends('investor.layout')

@section('title', 'Meus Documentos')

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-semibold text-zinc-900 dark:text-zinc-100">Meus Documentos</h1>
        <p class="mt-1 text-sm text-zinc-500">Acompanhe e baixe os documentos, relatórios e informativos relacionados aos seus investimentos.</p>
    </div>

    <livewire:investor.document-list />
</div>
@endsection