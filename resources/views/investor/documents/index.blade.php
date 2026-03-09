@extends('investor.layout')

@section('title', 'Meus Documentos')

@section('content')
<div class="bg-white border rounded p-6">
    <h1 class="text-xl font-semibold mb-4">Meus Documentos</h1>

    @if($documents->isEmpty())
        <p class="text-gray-600">Nenhum documento publicado disponível.</p>
    @else
        <ul class="space-y-2">
            @foreach($documents as $d)
                <li class="p-3 border rounded flex items-center justify-between gap-4">
                    <div>
                        <div class="font-medium">{{ $d->title }}</div>
                        <div class="text-sm text-gray-600">Categoria: {{ $d->category }}</div>
                    </div>
                    <a class="px-3 py-2 rounded bg-gray-900 text-white text-sm"
                       href="{{ route('investor.documents.download', $d) }}">
                        Baixar
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
@endsection