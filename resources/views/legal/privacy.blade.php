{{-- The controller section is written; the rest is Pedro's (or his lawyer's) to write (GDPR: the
     company becomes the processor of the freelancers' clients' data). --}}
@extends('legal.layout', ['sections' => [
    __('Data controller') => 'legal.partials.controller',
    __('Data we collect') => null,
    __('How we use data and on what legal basis') => null,
    __('Your clients’ data (processing on your behalf)') => null,
    __('Sub-processors') => null,
    __('Data retention') => null,
    __('Your rights') => null,
    __('Contacts and complaints') => null,
]])

@section('title', __('Privacy Policy'))
