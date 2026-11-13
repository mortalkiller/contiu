@extends('errors.layout')

@section('code', '401')
@section('title', __('Sign-in required'))
@section('message', __('Sign in to your account to view this page.'))

@section('actions')
    <a class="btn btn-primary" href="{{ \App\Support\PanelLocation::url('login') }}">{{ __('Sign in') }}</a>
@endsection
