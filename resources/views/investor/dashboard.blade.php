@extends('investor.layout')

@section('title', 'Início - Portal do Investidor')

@section('content')
<div class="bg-white border rounded p-6">
    <h1 class="text-xl font-semibold">Bem-vindo, {{ auth('investor')->user()->name }}</h1>
    <p class="text-gray-600 mt-2">Acesse suas emissões e documentos pelo menu acima.</p>
</div>
@endsection