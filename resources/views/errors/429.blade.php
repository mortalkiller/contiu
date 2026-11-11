@extends('errors.layout')

@section('code', '429')
@section('title', __('Too many requests'))
@section('message', __('Too many requests were made in a short time. Wait a minute and try again.'))
