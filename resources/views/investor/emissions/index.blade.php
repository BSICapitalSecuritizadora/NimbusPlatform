@extends('investor.layout')

@section('title', 'Minhas Emissões')

@section('content')
<div class="bg-white border rounded p-6">
    <h1 class="text-xl font-semibold mb-4">Minhas Emissões</h1>

    @if($emissions->isEmpty())
        <p class="text-gray-600">Nenhuma emissão vinculada.</p>
    @else
        <ul class="space-y-2">
            @foreach($emissions as $e)
                <li class="p-3 border rounded">
                    <div class="font-medium">{{ $e->name }}</div>
                    <div class="text-sm text-gray-600">Tipo: {{ $e->type }} | Status: {{ $e->status }}</div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
@endsection