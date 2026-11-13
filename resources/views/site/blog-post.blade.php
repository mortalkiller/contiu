{{--
    An article of the blog (App\Support\Blog), as in the SiteArtigo mock (top: SiteTopoArtigo, with
    the reading bar under the top bar). Each article's own view
    (site/blog/posts/{slug}) extends this one and fills in the headline, the lede, the text, the
    table of contents and the call to action beside it.
--}}
@extends('site.layout', ['current' => 'blog', 'navTone' => 'ink', 'navProgress' => true])

@section('content')
<section class="tp tp-light tp-ar"><div class="st-wrap">
<div class="tp-head"><nav class="tp-crumbs" aria-label="Caminho"><a href="{{ route('site.blog') }}">Blogue</a> › <a href="{{ route('site.blog', ['categoria' => $post['category']]) }}">{{ $post['category_name'] }}</a></nav><h1 class="st-h1">@yield('headline')</h1><p class="st-lede">@yield('lede')</p>
<p class="tp-by"><i>C</i><span><b>Equipa Contiu</b><br>{{ $post['published_label'] }} · {{ $post['minutes'] }} min de leitura</span></p></div>
<div class="tp-cover"><figure class="st-ph" style="max-width: none" aria-hidden="true"><span>Imagem de capa por gerar</span><b>img/{{ $post['image'] }}-capa.webp</b><span>2400 × 1030</span></figure></div>
</div></section>

<div class="st-wrap st-art">
<article class="st-prose">
@yield('article')
<div class="st-author"><span class="st-author-mark"><img src="{{ asset('brand/contiu/contiu-symbol-white.svg') }}" alt=""></span><div><b>Equipa Contiu</b><p>Escrevemos sobre impostos para quem trabalha por conta própria.</p></div></div>
</article>
<aside class="st-aside" aria-label="Neste artigo">
<nav class="st-toc" aria-label="Índice" data-site-toc><b>Neste artigo</b>@yield('toc')</nav>
<div class="st-cta-card"><b>@yield('aside-title')</b><p>@yield('aside-text')</p><a class="st-btn st-btn--pink st-btn--sm" href="{{ $signUpUrl }}">Experimenta grátis</a></div>
<div class="st-share" data-site-share data-url="{{ url()->current() }}" data-title="{{ $post['title'] }}"><span>Partilhar</span><button type="button" aria-label="Copiar a ligação" data-share="copy"><svg class="c-ic c-ic--sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 14a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1M14 10a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/></svg></button><button type="button" aria-label="Partilhar no LinkedIn" data-share="linkedin"><svg class="c-ic c-ic--sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9v11M6 5v.01M10 20v-6a4 4 0 0 1 8 0v6M10 9v11"/></svg></button><button type="button" aria-label="Enviar por email" data-share="email"><svg class="c-ic c-ic--sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18v12H3zM3 7l9 6 9-6"/></svg></button></div>
</aside>
</div>

<section class="st-sec st-sec--sunken"><div class="st-wrap">
<div class="st-catbar"><h2 class="st-h2">Continua a <b>ler</b></h2><a class="st-btn st-btn--line" href="{{ route('site.blog') }}">Ver todos os artigos @include('site.partials.arrow')</a></div>
<div class="st-grid-3">@foreach ($related as $relatedPost)@include('site.partials.post', ['post' => $relatedPost])@endforeach</div>
</div></section>
@include('site.partials.band', ['title' => 'Deixa as contas com o Contiu', 'lede' => 'O Contiu faz as contas ao teu IRS, IVA e Segurança Social e diz-te quanto pôr de parte.'])
@endsection
