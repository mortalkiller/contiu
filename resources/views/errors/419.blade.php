@extends('errors.layout')

@section('code', '419')
@section('title', __('Session expired'))
@section('message', __('The page expired for security after a period of inactivity. Reload and try again.'))

@section('actions')
    <button type="button" class="btn btn-primary" onclick="location.reload()">{{ __('Reload') }}</button>
    <a class="btn" href="{{ \App\Support\PanelLocation::url() }}">{{ __('Back to dashboard') }}</a>
@endsection
