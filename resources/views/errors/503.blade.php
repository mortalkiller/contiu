@extends('errors.layout')

@section('code', '503')
@section('title', __('Under maintenance'))
@section('message', __('Contiu is being updated. Come back in a few minutes.'))

@section('actions')
    <button type="button" class="btn btn-primary" onclick="location.reload()">{{ __('Try again') }}</button>
@endsection
