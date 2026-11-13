{{-- The themes, the $open one unfolded with its articles ($current marked). --}}
@php($chevron = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>')
<nav class="ha-tree" aria-label="Temas">@foreach (\App\Support\HelpArticles::themes() as $treeTheme)
@if ($treeTheme['slug'] === $open)
<div class="ha-tree-open"><a href="{{ $treeTheme['url'] }}" aria-current="{{ $current === null ? 'page' : 'true' }}">{{ $treeTheme['name'] }}{!! $chevron !!}</a><nav aria-label="{{ $treeTheme['name'] }}">@foreach ($treeTheme['articles'] as $treeArticle)<a href="{{ $treeArticle['url'] }}" @if ($treeArticle['slug'] === $current) aria-current="page" @endif>{{ $treeArticle['question'] }}</a>@endforeach</nav></div>
@else
<a href="{{ $treeTheme['url'] }}">{{ $treeTheme['name'] }}<span class="ha-tree-count"><em>{{ $treeTheme['articles']->count() }}</em>{!! $chevron !!}</span></a>
@endif
@endforeach</nav>
