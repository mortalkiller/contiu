{{--
    Contiu's favicons and app icons (public/favicons, from the Contiu favicon pack), for every
    page: the panel, the public site, the legal and the error pages.
--}}
<link rel="icon" href="{{ asset('favicons/favicon.svg') }}" type="image/svg+xml">
<link rel="icon" href="{{ asset('favicons/favicon.ico') }}" sizes="16x16 32x32 48x48 64x64">
<link rel="icon" href="{{ asset('favicons/favicon-32x32.png') }}" type="image/png" sizes="32x32">
<link rel="icon" href="{{ asset('favicons/favicon-16x16.png') }}" type="image/png" sizes="16x16">
<link rel="apple-touch-icon" href="{{ asset('favicons/apple-touch-icon.png') }}" sizes="180x180">
<link rel="manifest" href="{{ asset('favicons/site.webmanifest') }}">
<meta name="theme-color" content="{{ $themeColor ?? '#0B2E23' }}">
