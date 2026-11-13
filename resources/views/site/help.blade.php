@extends('site.layout', ['current' => 'help', 'navTone' => 'ink', 'rootClass' => 'ha'])

@section('content')
{{--
    The help centre's start (canvas SiteAjudaCentro): the top as it was (search and «Mais
    procuradas»), then the most asked articles and every article by theme. Each question of the
    help page is an article with a page of its own (App\Support\HelpArticles).
--}}
@php($themes = \App\Support\HelpArticles::themes())
<section class="tp tp-light tp-ab"><div class="st-wrap tp-in">
<div class="tp-copy"><h1 class="st-h1">Como podemos <b>ajudar?</b></h1><p class="st-lede">As respostas às perguntas mais comuns. Se não encontrares a tua, escreve-nos.</p>@include('site.help.partials.search-form')@include('site.help.partials.themes-links')</div>
<div class="tp-top5"><h2>Mais procuradas</h2>@foreach (\App\Support\HelpArticles::popular() as $article)<a href="{{ $article['url'] }}">{{ $article['question'] }}</a>@endforeach</div>
</div></section>
<section class="st-sec st-sec--sunken" style="padding-top: 72px" id="perguntas"><div class="st-wrap">
<h2 class="ha-h" style="margin-bottom: 32px">Perguntas <b>mais frequentes</b></h2>
<div class="ha-cards">@foreach (\App\Support\HelpArticles::popular() as $article)<a class="ha-card" href="{{ $article['url'] }}"><b>{{ $article['question'] }}</b><span>{{ \Illuminate\Support\Str::limit($article['answer'], 170) }}</span><em>Ler artigo {!! view('site.partials.arrow')->render() !!}</em></a>@endforeach</div>
</div></section>
<section class="st-sec" style="padding-bottom: 24px"><div class="st-wrap">
<h2 class="ha-h" style="margin-bottom: 32px">Procurar por <b>tema</b></h2>
<div class="ha-theme-cols">@foreach ($themes as $theme)<div><a class="ha-row ha-row--head" href="{{ $theme['url'] }}"><b>{{ $theme['name'] }}</b><span>{{ trans_choice(':count artigo|:count artigos', $theme['articles']->count()) }}</span></a>@foreach ($theme['articles'] as $article)<a class="ha-row ha-row--compact" href="{{ $article['url'] }}"><b>{{ $article['question'] }}</b></a>@endforeach</div>@endforeach</div>
</div></section>
@include('site.help.partials.contact')
@endsection
