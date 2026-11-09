{{--
    Public legal page (terms, privacy), open without login. Self-contained like errors/layout
    (inline CSS, no Vite), with the same brand header. Each page fills in the title and sections.
--}}
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Out of search engines while the pages hold placeholder text: remove with Pedro's text. --}}
    <meta name="robots" content="noindex">
    <title>@yield('title') · Contiu</title>
    <link rel="icon" href="{{ asset('brand/contiu/favicon.ico') }}" sizes="any">
    <link rel="icon" href="{{ asset('brand/contiu/favicon.svg') }}" type="image/svg+xml">
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
        <a href="{{ url('/') }}" aria-label="Contiu — ir para o início">
            <img src="{{ asset('brand/contiu/contiu-logo-white.svg') }}" alt="Contiu">
        </a>
    </header>

    <main>
        <article>
            <span class="version">Versão {{ config('contiu.terms_version') }}</span>
            <h1>@yield('title')</h1>

            @foreach ($sections as $section)
                <h2>{{ $loop->iteration }}. {{ $section }}</h2>
                <p class="placeholder">Texto a fornecer pelo Pedro.</p>
            @endforeach

            <nav>
                <a href="{{ url('/termos') }}">Termos de Serviço</a>
                <a href="{{ url('/privacidade') }}">Política de Privacidade</a>
            </nav>
        </article>
    </main>

    <footer>Contiu</footer>
</body>
</html>
