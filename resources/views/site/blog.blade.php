@extends('site.layout', ['current' => 'blog', 'navTone' => 'ink'])


@section('content')
{{-- The top (canvas SiteTopoBlogueA): a big title, the categories and search, the featured article on a night card. --}}
<section class="tp tp-light tp-ba"><div class="st-wrap">
<div class="tp-top"><h1 class="tp-word">Blogue<b>.</b></h1><p class="st-lede">Impostos explicados a quem trabalha por conta própria: o IRS, o IVA e a Segurança Social em português claro.</p></div>
<div class="st-catbar"><nav class="st-cats" aria-label="Categorias"><a href="{{ route('site.blog') }}" @if ($category === null) aria-current="true" @endif>Tudo</a>@foreach (\App\Support\Blog::CATEGORIES as $slug => $name)<a href="{{ route('site.blog', ['categoria' => $slug]) }}" @if ($category === $slug) aria-current="true" @endif>{{ $name }}</a>@endforeach</nav>
<form class="st-search" role="search" method="get" action="{{ route('site.blog') }}"><svg class="c-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14zM21 21l-5-5"/></svg><label class="c-sr" for="site-blog-search">Pesquisar artigos</label><input type="search" id="site-blog-search" name="q" value="{{ $search }}" placeholder="Pesquisar artigos">@if ($category)<input type="hidden" name="categoria" value="{{ $category }}">@endif</form></div>
@if ($featured)
<a class="st-feat" href="{{ $featured['url'] }}"><figure class="st-ph " style="aspect-ratio: auto; max-width: none" aria-hidden="true"><span>Imagem por gerar</span><b>img/{{ $featured['image'] }}.webp</b><span>1600 × 900</span></figure><div class="st-feat-copy"><span class="st-pill st-pill--pink">Em destaque</span><span class="st-post-cat" style="color: var(--lav)">{{ $featured['category_name'] }}</span><h2 class="st-h2">{{ $featured['title'] }}</h2><p>{{ $featured['excerpt'] }}</p><span class="st-meta"><span>{{ $featured['published_label'] }}</span><i></i><span>{{ $featured['minutes'] }} min de leitura</span></span><span class="st-read">Ler artigo @include('site.partials.arrow')</span></div></a>
@endif
</div></section>

<section class="st-sec" style="padding-top: 72px"><div class="st-wrap">
@if ($posts->isEmpty())
<p class="st-muted">Ainda não há artigos com {{ $search !== '' ? '«'.$search.'»' : 'esta categoria' }}.</p>
@else
<div class="st-grid-3" style="row-gap: 64px">
@foreach ($posts as $post)@include('site.partials.post')@endforeach
</div>
@endif
</div></section>
@include('site.partials.band', ['title' => 'Deixa as contas com o Contiu', 'lede' => 'Lê com calma. Enquanto isso, o Contiu faz as contas ao teu IRS, IVA e Segurança Social.'])
@endsection
