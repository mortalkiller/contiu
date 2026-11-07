@extends('errors.layout')

@section('code', '401')
@section('title', 'Sessão necessária')
@section('message', 'Para veres esta página tens de entrar na tua conta.')

@section('actions')
    <a class="btn btn-primary" href="{{ url('/login') }}">Entrar</a>
@endsection
