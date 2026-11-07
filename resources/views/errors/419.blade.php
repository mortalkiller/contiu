@extends('errors.layout')

@section('code', '419')
@section('title', 'A sessão expirou')
@section('message', 'Ficaste algum tempo sem atividade e, por segurança, a página expirou. Recarrega e tenta outra vez.')

@section('actions')
    <button type="button" class="btn btn-primary" onclick="location.reload()">Recarregar</button>
    <a class="btn" href="{{ url('/') }}">Voltar ao painel</a>
@endsection
