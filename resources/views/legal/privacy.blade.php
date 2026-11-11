{{-- Structure only: the text is Pedro's (or his lawyer's) to write (GDPR: Pedro becomes the
     processor of the freelancers' clients' data). --}}
@extends('legal.layout', ['sections' => [
    __('Data controller'),
    __('Data we collect'),
    __('How we use data and on what legal basis'),
    __('Your clients’ data (processing on your behalf)'),
    __('Sub-processors'),
    __('Data retention'),
    __('Your rights'),
    __('Contacts and complaints'),
]])

@section('title', __('Privacy Policy'))
