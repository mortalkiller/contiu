{{-- The provider and the accountant sections are written; the rest is Pedro's (or his lawyer's). --}}
@extends('legal.layout', ['sections' => [
    __('Who provides the service') => 'legal.partials.provider',
    __('The service') => null,
    __('Certified accountant service') => 'legal.partials.accountant',
    __('Account and registration') => null,
    __('Trial period and subscription') => null,
    __('Payments and billing') => null,
    __('Cancellation and account closure') => null,
    __('Your data and your clients’ data') => null,
    __('Liability') => null,
    __('Changes to these terms') => null,
    __('Applicable law and contacts') => null,
]])

@section('title', __('Terms of Service'))
