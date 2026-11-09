<?php

namespace App\Filament\Support;

/** Contiu colour palettes for Filament. */
final class BrandPalette
{
    public const string PRIMARY = '#18b980';

    public const string ACTION_GREEN = '#0a5b42';

    /**
     * The brand green is shade 500. Use darker shades for light-mode text and surfaces
     * that carry white text.
     *
     * @return array<int, string>
     */
    public static function primary(): array
    {
        return [
            50 => '#effcf6',
            100 => '#d8f7e8',
            200 => '#b2eed4',
            300 => '#7de0b7',
            400 => '#48cf98',
            500 => self::PRIMARY,
            600 => '#087c55',
            700 => '#076b49',
            800 => '#08563c',
            900 => '#084130',
            950 => '#06291d',
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
