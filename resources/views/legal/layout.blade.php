{{--
    A legal page (Termos e Condições, Política de Privacidade) on the public site, as in the canvas
    board «Legal · C» (site-legal.css, classes lg-): the site's menu and footer, a light top with
    the title, the switch between both texts and the dates, then the text in one column on white.
    Each page passes its $sections (title => partial), $document ('terms' or 'privacy') and $lede.
    What is still to confirm shows highlighted (.placeholder), and while there is any the page
    stays out of search engines.
--}}
@php
    $title = trim($__env->yieldContent('title'));
    $rendered = collect($sections)->map(fn (string $partial): string => view($partial)->render());
    $words = count(preg_split('/\s+/u', trim(strip_tags($rendered->implode(' '))), -1, PREG_SPLIT_NO_EMPTY));
    $minutes = max(1, (int) round($words / 200));
    $inForce = \Illuminate\Support\Carbon::parse(config('contiu.terms_version'))->locale('pt_PT')->translatedFormat('j \d\e F \d\e Y');
    $canonical = route($document === 'terms' ? 'legal.terms' : 'legal.privacy');
    $seo = \App\Support\SiteSeo::forLegal($title, $lede, $canonical, ! $rendered->contains(fn (string $html): bool => str_contains($html, 'placeholder')));
@endphp
@extends('site.layout', ['current' => null, 'navTone' => 'ink', 'rootClass' => 'lg'])

@section('content')
<section class="tp tp-light lg-c lg-c--plain"><div class="st-wrap"><div class="tp-head">
<p class="lg-kick">Legal</p>
<h1 class="st-h1">{!! $document === 'terms' ? 'Termos e <b>Condições</b>' : 'Política de <b>Privacidade</b>' !!}</h1>
<p class="st-lede">{{ $lede }}</p>
<nav class="lg-switch" aria-label="Documentos legais"><a href="{{ route('legal.terms') }}" @if ($document === 'terms') aria-current="page" @endif>Termos e Condições</a><a href="{{ route('legal.privacy') }}" @if ($document === 'privacy') aria-current="page" @endif>Política de Privacidade</a></nav>
<p class="lg-meta"><span><i></i>Em vigor desde {{ $inForce }}</span><span>Versão {{ config('contiu.terms_version') }}</span><span>{{ $minutes }} min de leitura</span></p>
</div></div></section>
<section class="lg-body lg-body--plain"><div class="st-wrap lg-body-in"><article class="lg-text">
@foreach ($rendered as $section => $html)
<h2 id="{{ \Illuminate\Support\Str::slug($section) }}">{{ $loop->iteration }}. {{ $section }}</h2>
{!! $html !!}
@endforeach
</article></div></section>
@endsection
