{{-- Contiu favicons and app icons (from the Contiu logo and favicon kit). --}}
{{-- Colours of the palette the user chose in Perfil → Aparência (Contiu when signed out). --}}
@php($palette = \App\Support\Appearance\PaletteRegistry::current())
<style>
    :root {
        @foreach ($palette->cssVariables() as $name => $value)
            {{ $name }}: {{ $value }};
        @endforeach
    }

    :root.dark {
        @foreach ($palette->cssVariables(dark: true) as $name => $value)
            {{ $name }}: {{ $value }};
        @endforeach
    }
</style>
<link rel="icon" href="{{ asset('brand/contiu/favicon.ico') }}" sizes="16x16 32x32 48x48">
<link rel="icon" href="{{ asset('brand/contiu/favicon.svg') }}" type="image/svg+xml" sizes="any">
<link rel="apple-touch-icon" href="{{ asset('brand/contiu/apple-touch-icon.png') }}" sizes="180x180">
<link rel="manifest" href="{{ asset('brand/contiu/site.webmanifest') }}">
