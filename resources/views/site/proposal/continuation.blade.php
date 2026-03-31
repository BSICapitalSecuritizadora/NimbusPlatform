@extends('site.layout')

@section('title', 'Formulário de Empreendimento')

@section('content')
    <livewire:proposals.continuation-form :access="$access" :proposal="$proposal" />
@endsection
