@extends('errors.layout')

@section('code', '503')
@section('title', 'Em manutenção')
@section('message', 'A Contiu está a ser atualizada. Volta dentro de alguns minutos.')

@section('actions')
    <button type="button" class="btn btn-primary" onclick="location.reload()">Tentar outra vez</button>
@endsection
