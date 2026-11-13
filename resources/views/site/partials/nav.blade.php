{{--
    The site's top bar: transparent over the page's hero, indigo once the page scrolls
    (is-scrolled, set by resources/js/site.js), "Recursos" opening Blogue and Ajuda. Each page's
    top (canvas SiteTopo*) sets $navTone: 'ink' for a light top (dark text and logo), 'green' for
    the green top of Preços, 'login-night' for the night-and-green top of Contabilista. An article
    passes $navProgress for the reading bar under the top bar.
--}}
@php($toneClass = match ($navTone ?? null) {
    'ink' => ' st-nav--ink',
    'green' => ' st-nav--ink st-nav--ongreen',
    'login-night' => ' st-nav--loginnight',
    default => '',
})
@php($is = fn (string $page): string => $current === $page ? ' aria-current="page"' : '')
<header class="st-nav{{ $toneClass }}" data-site-nav><div class="st-wrap st-nav-in"><a class="st-nav-logo" href="{{ route('site.home') }}" aria-label="Contiu, página inicial"><img class="st-logo" src="{{ asset('brand/contiu/contiu-logo-white.svg') }}" alt="Contiu"></a><nav class="st-menu" aria-label="Principal"><a href="{{ route('site.features') }}"{!! $is('features') !!}>Funcionalidades</a><a href="{{ route('site.pricing') }}"{!! $is('pricing') !!}>Preços</a><a href="{{ route('site.accountant') }}"{!! $is('accountant') !!}>Contabilista</a><div class="st-drop" data-site-drop><button type="button" class="st-drop-btn" aria-expanded="false" aria-controls="site-recursos">Recursos<svg class="c-ic c-ic--sm" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button><div class="st-drop-menu" id="site-recursos" hidden><a href="{{ route('site.blog') }}"{!! $is('blog') !!}>Blogue</a><a href="{{ route('site.help') }}"{!! $is('help') !!}>Ajuda</a></div></div></nav><a class="st-btn st-btn--pink st-btn--sm st-nav-login" href="{{ $signInUrl }}">Entrar</a></div>@if ($navProgress ?? false)<span class="tp-progress" aria-hidden="true" data-site-progress><i></i></span>@endif</header>
