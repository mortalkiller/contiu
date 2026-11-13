{{--
    Public legal page (terms, privacy), open without login. Self-contained like errors/layout
    (inline CSS, no Vite), with the same brand header. Each page fills in the title and sections:
    section title => the partial with its text, or null while the text is still to be written.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Out of search engines while the pages hold placeholder text: remove with Pedro's text. --}}
    <meta name="robots" content="noindex">
    <title>@yield('title') · Contiu</title>
    @include('partials.favicons')
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=geist:400,500,600,700&display=swap" rel="stylesheet">
    @php($brand = \App\Filament\Support\BrandPalette::primary())
    <style>
        :root {
            --brand: {{ $brand[500] }};
            --brand-link: {{ $brand[600] }};
            --bg: #ffffff;
            --text: #0a0a0a;
            --muted: #525252;
            --border: #e5e5e5;
            --soft: #f5f5f5;
            --note: #fef3c7;
            --note-text: #78350f;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --brand-link: {{ $brand[300] }};
                --bg: #0a0a0a;
                --text: #fafafa;
                --muted: #a3a3a3;
                --border: #262626;
                --soft: #262626;
                --note: #422006;
                --note-text: #fde68a;
            }
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; min-height: 100%; }
        body {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            background: var(--bg);
            color: var(--text);
            font-family: 'Geist', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        header {
            background: var(--brand);
            padding: 0 24px;
            height: 64px;
            display: flex;
            align-items: center;
        }
        header img { height: 22px; display: block; }
        main {
            flex: 1;
            padding: 48px 16px;
            background: linear-gradient(to bottom, color-mix(in srgb, var(--brand) 8%, transparent), transparent 220px);
        }
        article { max-width: 720px; margin: 0 auto; }
        .version {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 4px 12px;
            border-radius: 999px;
            background: var(--soft);
            color: var(--muted);
            font-size: 13px;
            font-weight: 500;
        }
        .version::before {
            content: '';
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: var(--brand);
        }
        h1 {
            margin: 20px 0 24px;
            font-size: clamp(28px, 6vw, 40px);
            line-height: 1.15;
            font-weight: 700;
            letter-spacing: -.02em;
        }
        h2 {
            margin: 32px 0 8px;
            font-size: 18px;
            font-weight: 600;
        }
        p { margin: 0; color: var(--muted); font-size: 16px; line-height: 1.6; }
        p + p { margin-top: 12px; }
        dl { margin: 12px 0 0; display: grid; grid-template-columns: max-content 1fr; gap: 6px 16px; font-size: 15px; }
        dt { color: var(--muted); }
        dd { margin: 0; font-weight: 500; }
        dd a, p a { color: var(--brand-link); }
        h3 { margin: 20px 0 6px; font-size: 16px; font-weight: 600; }
        ul { margin: 12px 0 0; padding-left: 20px; color: var(--muted); font-size: 16px; line-height: 1.6; }
        li + li { margin-top: 4px; }
        table { width: 100%; margin-top: 12px; border-collapse: collapse; font-size: 15px; }
        th, td { padding: 8px 10px; border-bottom: 1px solid var(--border); text-align: left; }
        th { color: var(--muted); font-weight: 500; }
        .placeholder-inline { padding: 1px 6px; border-radius: 6px; background: var(--note); color: var(--note-text); font-size: 14px; }
        p + .placeholder, .placeholder + p, ul + .placeholder, table + .placeholder, dl + p { margin-top: 12px; }
        .placeholder {
            padding: 12px 16px;
            border-radius: 8px;
            background: var(--note);
            color: var(--note-text);
            font-size: 14px;
            font-weight: 500;
        }
        nav { margin-top: 40px; padding-top: 20px; border-top: 1px solid var(--border); font-size: 14px; }
        nav a { color: var(--brand-link); font-weight: 600; margin-right: 16px; }
        footer {
            padding: 20px 16px;
            text-align: center;
            color: var(--muted);
            font-size: 13px;
        }
    </style>
</head>
<body>
    <header>
        <a href="{{ url('/') }}" aria-label="{{ __('Contiu — go to home') }}">
            <img src="{{ asset('brand/contiu/contiu-logo-white.svg') }}" alt="Contiu">
        </a>
    </header>

    <main>
        <article>
            <span class="version">{{ __('Version :version', ['version' => config('contiu.terms_version')]) }}</span>
            <h1>@yield('title')</h1>

            @foreach ($sections as $section => $partial)
                <h2>{{ $loop->iteration }}. {{ $section }}</h2>
                @if ($partial)
                    @include($partial)
                @else
                    <p class="placeholder">{{ __('Text to be provided by Pedro.') }}</p>
                @endif
            @endforeach

            <nav>
                <a href="{{ url('/termos') }}">{{ __('Terms of Service') }}</a>
                <a href="{{ url('/privacidade') }}">{{ __('Privacy Policy') }}</a>
            </nav>
        </article>
    </main>

    <footer>{{ __('Contiu is a service of :company', ['company' => config('contiu.company.name')]) }}</footer>
</body>
</html>
