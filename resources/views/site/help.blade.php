@extends('site.layout', ['current' => 'help', 'navTone' => 'ink'])


@section('content')
{{-- The top (canvas SiteTopoAjudaB): the search filters the questions below as you type (resources/js/site.js, data-site-faq-search). --}}
@php($groups = collect(\App\Support\SiteFaq::for('help'))->groupBy('group'))
<section class="tp tp-light tp-ab"><div class="st-wrap tp-in">
<div class="tp-copy"><h1 class="st-h1">Como podemos <b>ajudar?</b></h1><p class="st-lede">As respostas às perguntas mais comuns. Se não encontrares a tua, escreve-nos.</p><form class="tp-search" role="search" action="#perguntas" data-site-faq-search><svg class="c-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14zM21 21l-5-5"/></svg><label for="site-help-search">Pesquisar nas perguntas</label><input type="search" id="site-help-search" placeholder="Pesquisa uma dúvida" autocomplete="off"><button type="submit">Pesquisar</button></form><p class="tp-links">Temas: @foreach ($groups->keys() as $group)<a href="#{{ \Illuminate\Support\Str::slug($group) }}">{{ $group }}</a>@endforeach</p></div>
<div class="tp-top5"><h2>Mais procuradas</h2>@foreach (\App\Support\SiteFaq::popular() as $item)<a href="#{{ $item['id'] }}">{{ $item['question'] }}</a>@endforeach</div>
</div></section>
<section class="st-sec st-sec--sunken" style="padding-top: 56px" id="perguntas"><div class="st-wrap st-wrap--narrow" data-site-faq-list>
@foreach ($groups as $group => $items)
<div data-site-faq-group><h2 class="st-faq-group" id="{{ \Illuminate\Support\Str::slug($group) }}" @if ($loop->first) style="margin-top: 0" @endif>{{ $group }}</h2>
@include('site.partials.faq', ['items' => $items])</div>
@endforeach
<p class="st-muted" data-site-faq-empty hidden>Não encontrámos nenhuma pergunta com essas palavras. <a href="#contacto">Escreve-nos</a> e respondemos por email.</p>
</div></section>
<section class="st-sec" id="contacto"><div class="st-wrap st-wrap--narrow">
<div class="st-plan" style="align-items: center; text-align: center; padding: 48px 32px"><h2 class="st-h2">Não encontraste a resposta?</h2><p class="st-lede">Escreve-nos e respondemos por email.</p><a class="st-btn st-btn--primary" style="align-self: center" href="mailto:{{ $company['email'] }}">{{ $company['email'] }}</a></div>
</div></section>
@include('site.partials.band')
@endsection
