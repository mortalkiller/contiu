@extends('site.layout', ['current' => 'help', 'navTone' => 'ink', 'rootClass' => 'ha', 'seo' => \App\Support\SiteSeo::forHelp('article', $article['question'], $article['answer'], $article['url'], [[$article['theme'], route('site.help.theme', $article['theme_slug'])], [$article['question'], null]], $article)])

@section('content')
{{--
    A help article (canvas SiteAjudaArtigo), in one column: the question, the answer as the lede
    and, when the article has one, its longer text (site/help/articles/{slug}).
--}}
@include('site.help.partials.top')
<section class="st-sec" style="padding-top: 48px"><div class="st-wrap"><article class="ha-art">
@include('site.help.partials.crumbs', ['trail' => [[$article['theme'], route('site.help.theme', $article['theme_slug'])], [$article['question'], null]]])
<h1 class="ha-art-title">{{ $article['question'] }}</h1>
<p class="ha-art-lede">{{ $article['answer'] }}</p>
<div class="ha-art-bar" data-site-share data-url="{{ $article['url'] }}" data-title="{{ $article['question'] }}"><button type="button" data-share="copy"><svg class="c-ic c-ic--sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 14a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1M14 10a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/></svg>Copiar ligação</button><button type="button" data-share="email"><svg class="c-ic c-ic--sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18v12H3zM3 7l9 6 9-6"/></svg>Enviar por email</button></div>
@if ($article['has_text'])
<div class="st-prose">@include(\App\Support\HelpArticles::view($article['slug']))</div>
@endif
<h2 class="ha-h ha-related-head">Artigos <b>relacionados</b></h2>
<div class="ha-rows ha-rows--ruled">@foreach ($related as $relatedArticle)@include('site.help.partials.row', ['article' => $relatedArticle, 'withTheme' => true])@endforeach</div>
</article></div></section>
@include('site.help.partials.contact')
@endsection
