{{-- Structure only: the text is Pedro's (or his lawyer's) to write. --}}
@extends('legal.layout', ['sections' => [
    __('Who provides the service'),
    __('The service'),
    __('Account and registration'),
    __('Trial period and subscription'),
    __('Payments and billing'),
    __('Cancellation and account closure'),
    __('Your data and your clients’ data'),
    __('Liability'),
    __('Changes to these terms'),
    __('Applicable law and contacts'),
]])

@section('title', __('Terms of Service'))
