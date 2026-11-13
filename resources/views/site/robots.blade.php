User-agent: *
@if ($onPanelDomain)
Disallow: /
@else
@if (\App\Support\PanelLocation::path() !== '')
Disallow: /{{ \App\Support\PanelLocation::path() }}/
@endif
Disallow: /dev/
Allow: /

Sitemap: {{ route('site.sitemap') }}
@endif
