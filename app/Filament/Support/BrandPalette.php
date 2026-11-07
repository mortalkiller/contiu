<?php

namespace App\Filament\Support;

/**
 * Filament colour palettes built from the printee/Pressiu site tokens
 * (printee/resources/css/app.css, `@theme` block). Shades marked "site" are the
 * exact site colours; the rest are interpolated to complete Filament's 50–950 scale.
 */
final class BrandPalette
{
    /**
     * Pressiu brand green (#415847, sampled from the site header). Filament fills primary
     * buttons with 600 in light mode and 500 in dark mode; the sidebar and topbar use 600
     * (light) and 900 (dark) through theme.css.
     *
     * @return array<int, string>
     */
    public static function primary(): array
    {
        return [
            50 => '#f2f6f2',
            100 => '#e3ece4', // site: moss-tint
            200 => '#c7d8ca',
            300 => '#9db9a3', // site: sage
            400 => '#7f9b85',
            500 => '#5f7864',
            600 => '#415847', // brand green
            700 => '#374b3d',
            800 => '#2d3e32',
            900 => '#243228',
            950 => '#131b16',
        ];
    }

    /**
     * Neutral greys (no warm tint) used by Filament for backgrounds, borders and text.
     * Pedro chose neutral over the site's warm paper/ink scale; 50 is his #f7f7f7.
     *
     * @return array<int, string>
     */
    public static function gray(): array
    {
        return [
            50 => '#f7f7f7',
            100 => '#f2f2f2',
            200 => '#e5e5e5',
            300 => '#d4d4d4',
            400 => '#a3a3a3',
            500 => '#737373',
            600 => '#525252',
            700 => '#404040',
            800 => '#262626',
            900 => '#171717',
            950 => '#0f0f0f',
        ];
    }

    /**
     * Clay accent, available as color('clay') on badges, stats and actions.
     *
     * @return array<int, string>
     */
    public static function clay(): array
    {
        return [
            50 => '#faf6f2',
            100 => '#f3eae1',
            200 => '#e6d4c3',
            300 => '#d7bea8',
            400 => '#c9a98e', // site: clay
            500 => '#b48f72',
            600 => '#9a755a',
            700 => '#7d5f49',
            800 => '#634c3b',
            900 => '#4d3b2f',
            950 => '#2a201a',
        ];
    }
}
