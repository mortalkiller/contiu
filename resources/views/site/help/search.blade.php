@extends('site.layout', ['current' => 'help', 'navTone' => 'ink', 'rootClass' => 'ha', 'seo' => \App\Support\SiteSeo::forHelp('search', filled($query) ? 'Pesquisa: '.$query : 'Pesquisa', 'Pesquisa no centro de ajuda do Contiu.', route('site.help.search'), [['Pesquisa', null]])])

@section('content')
{{-- The help search (canvas SiteAjudaPesquisa): the articles with every word searched, the words marked. --}}
@include('site.help.partials.top')
<section class="st-sec" style="padding-top: 48px"><div class="st-wrap">
@include('site.help.partials.crumbs', ['trail' => [['Pesquisa', null]]])
@if (filled($query))
<h1 class="ha-h">A procurar por «<mark>{{ $query }}</mark>»</h1>
<p class="ha-count">{{ trans_choice(':count artigo|:count artigos', $results->count()) }}</p>
@if ($results->isNotEmpty())
<div class="ha-rows ha-rows--ruled">@foreach ($results as $article)@include('site.help.partials.row', ['withTheme' => true])@endforeach</div>
@else
<div class="ha-none"><b>Não encontrámos nenhum artigo com essas palavras.</b><p>Experimenta outras palavras, procura por tema ou <a href="#contacto">escreve-nos</a>.</p></div>
@endif
@else
<h1 class="ha-h">Pesquisa na <b>ajuda</b></h1>
<p class="ha-count">Escreve uma dúvida acima ou procura por tema.</p>
@endif
</div></section>
@include('site.help.partials.contact')
@endsection
