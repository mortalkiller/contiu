@extends('site.layout', ['current' => 'help', 'navTone' => 'ink', 'rootClass' => 'ha', 'seo' => \App\Support\SiteSeo::forHelp('theme', $theme['name'], 'As perguntas e respostas sobre '.mb_strtolower($theme['name']).' no centro de ajuda do Contiu.', $theme['url'], [[$theme['name'], null]])])

@section('content')
{{-- A theme of the help centre (canvas SiteAjudaCategoria): the themes as a tree, this one open, and its articles. --}}
@include('site.help.partials.top')
<section class="st-sec" style="padding-top: 48px"><div class="st-wrap">
@include('site.help.partials.crumbs', ['trail' => [[$theme['name'], null]]])
<h1 class="ha-h">Procurar por <b>tema</b></h1>
<div class="ha-browse">@include('site.help.partials.tree', ['open' => $theme['slug'], 'current' => null])<div class="ha-rows">@foreach ($theme['articles'] as $article)@include('site.help.partials.row')@endforeach</div></div>
</div></section>
@include('site.help.partials.contact')
@endsection
