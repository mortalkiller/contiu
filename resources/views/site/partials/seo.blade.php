{{-- Search engines and link previews (App\Support\SiteSeo): meta tags, Open Graph, Twitter and JSON-LD. --}}
<title>{{ $seo['title'] }}</title>
<meta name="description" content="{{ $seo['description'] }}">
<meta name="robots" content="{{ $seo['robots'] }}">
<link rel="canonical" href="{{ $seo['canonical'] }}">
<link rel="alternate" hreflang="pt-PT" href="{{ $seo['canonical'] }}">
<meta property="og:site_name" content="Contiu">
<meta property="og:locale" content="pt_PT">
<meta property="og:type" content="{{ $seo['type'] }}">
<meta property="og:title" content="{{ $seo['title'] }}">
<meta property="og:description" content="{{ $seo['description'] }}">
<meta property="og:url" content="{{ $seo['canonical'] }}">
<meta property="og:image" content="{{ $seo['image'] }}">
<meta property="og:image:type" content="image/png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="{{ $seo['image_alt'] }}">
@if ($seo['published'])
<meta property="article:published_time" content="{{ $seo['published'] }}">
<meta property="article:section" content="{{ $seo['section'] }}">
@endif
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $seo['title'] }}">
<meta name="twitter:description" content="{{ $seo['description'] }}">
<meta name="twitter:image" content="{{ $seo['image'] }}">
<meta name="twitter:image:alt" content="{{ $seo['image_alt'] }}">
<script type="application/ld+json">{!! json_encode($seo['json_ld'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_THROW_ON_ERROR) !!}</script>
