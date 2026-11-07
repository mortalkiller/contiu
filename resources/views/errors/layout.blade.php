{{--
    Contiu error page. Self-contained (inline CSS, no Vite) so it still renders when the app
    itself is broken. Each errors/{code}.blade.php fills in the code, title, message and actions.
--}}
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') · Contiu</title>
    <link rel="icon" href="{{ asset('brand/contiu/favicon.ico') }}" sizes="any">
    <link rel="icon" href="{{ asset('brand/contiu/favicon.svg') }}" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=geist:400,500,600,700&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand: #415847;
            --brand-dark: #34473a;
            --bg: #ffffff;
            --surface: #ffffff;
            --text: #0a0a0a;
            --muted: #525252;
            --border: #e5e5e5;
            --soft: #f5f5f5;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0a0a0a;
                --surface: #171717;
                --text: #fafafa;
                --muted: #a3a3a3;
                --border: #262626;
                --soft: #262626;
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
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 48px 16px;
            background: linear-gradient(to bottom, rgba(65, 88, 71, .08), transparent 220px);
        }
        .card {
            width: 100%;
            max-width: 520px;
            text-align: center;
        }
        .code {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 4px 12px;
            border-radius: 999px;
            background: var(--soft);
            color: var(--muted);
            font-size: 13px;
            font-weight: 500;
            font-variant-numeric: tabular-nums;
            letter-spacing: .02em;
        }
        .code::before {
            content: '';
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: var(--brand);
        }
        h1 {
            margin: 20px 0 12px;
            font-size: clamp(28px, 6vw, 40px);
            line-height: 1.15;
            font-weight: 700;
            letter-spacing: -.02em;
        }
        p {
            margin: 0 auto;
            max-width: 440px;
            color: var(--muted);
            font-size: 16px;
            line-height: 1.6;
        }
        .actions {
            margin-top: 32px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: center;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            padding: 0 18px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--text);
            font: inherit;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: background .15s, border-color .15s;
        }
        .btn:hover { background: var(--soft); }
        .btn-primary { background: var(--brand); border-color: var(--brand); color: #fff; }
        .btn-primary:hover { background: var(--brand-dark); border-color: var(--brand-dark); }
        .btn:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }
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
        <a href="{{ url('/admin') }}" aria-label="Contiu — ir para o painel">
            <img src="{{ asset('brand/contiu/contiu-logo-white.svg') }}" alt="Contiu">
        </a>
    </header>

    <main>
        <div class="card">
            <span class="code">Erro @yield('code')</span>
            <h1>@yield('title')</h1>
            <p>@yield('message')</p>

            <div class="actions">
                @hasSection('actions')
                    @yield('actions')
                @else
                    <a class="btn btn-primary" href="{{ url('/admin') }}">Voltar ao painel</a>
                    <button type="button" class="btn" onclick="history.length > 1 ? history.back() : location.assign('{{ url('/admin') }}')">Voltar atrás</button>
                @endif
            </div>
        </div>
    </main>

    <footer>Contiu · se o problema continuar, tenta mais tarde.</footer>
</body>
</html>
