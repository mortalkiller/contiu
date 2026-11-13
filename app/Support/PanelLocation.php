<?php

namespace App\Support;

/**
 * Where the Filament panel is served (config contiu.panel_domain): at /app on the site's own
 * domain, or at the root of its own domain in production. The public site keeps the root of the
 * APP_URL host either way.
 */
final class PanelLocation
{
    public const string PATH = 'app';

    /** The panel's own domain ("app.contiu.pt"), or null when it shares the site's. */
    public static function domain(): ?string
    {
        return filled($domain = config('contiu.panel_domain')) ? (string) $domain : null;
    }

    /** The panel's path prefix: "app", or "" on its own domain. */
    public static function path(): string
    {
        return self::domain() === null ? self::PATH : '';
    }

    /** An absolute URL inside the panel ("" for its root, which sends a guest to sign in). */
    public static function url(string $path = ''): string
    {
        $path = trim(self::path().'/'.ltrim($path, '/'), '/');

        if (($domain = self::domain()) === null) {
            return url($path);
        }

        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        return rtrim("{$scheme}://{$domain}/{$path}", '/');
    }

    /** The host the public site answers on (the APP_URL host), when the panel has its own. */
    public static function siteDomain(): ?string
    {
        return self::domain() === null ? null : (parse_url((string) config('app.url'), PHP_URL_HOST) ?: null);
    }
}
