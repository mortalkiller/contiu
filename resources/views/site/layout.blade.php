{{--
    The public site, as in the "Site público · proposta" mocks: the top bar, the page and the
    footer on a .c-root.st page (resources/css/site.css). $current names the page for the menu and
    its search-engine data (App\Support\SiteSeo); an article passes its $seoPost.
--}}
@php
    $current ??= null;
    // A page family's own styles hang from a class on the root (help: "ha", resources/css/site.css).
    // A help page under /ajuda passes its own $seo (SiteSeo::forHelp()).
    $seo ??= \App\Support\SiteSeo::for($current ?? 'home', $seoPost ?? null);
@endphp
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('site.partials.seo')
    @include('partials.favicons', ['themeColor' => '#0C0E1D'])
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800|geist-mono:500&display=swap" rel="stylesheet">
    @vite(['resources/css/site.css', 'resources/js/site.js'])
</head>
<body>
<div @class(['c-root st', $rootClass ?? null]) data-theme="light">
@include('site.partials.nav')
@yield('content')
@include('site.partials.footer')
</div>
</body>
</html>
