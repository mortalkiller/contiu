<?php

namespace App\Support\Appearance;

use App\Filament\Support\BrandPalette;
use Filament\Support\Colors\Color;

/**
 * One colour palette for the panel: the brand colour, the main button, the navigation bar and
 * the page background, in light and dark mode. Status colours (success, warning, danger, info)
 * are not part of a palette: they are the same in all of them.
 */
final readonly class Palette
{
    private const string LIGHT_TEXT = '#ffffff';

    private const string DARK_TEXT = '#111111';

    /**
     * @param  string  $primary  Brand colour: Filament's "primary" scale (links, focus, charts).
     * @param  string  $action  Main button background in light mode.
     * @param  string  $navigation  Navigation bar background in light mode.
     * @param  string  $background  Page background behind cards and tables in light mode.
     * @param  string  $actionDark  Main button background in dark mode.
     * @param  string  $navigationDark  Navigation bar background in dark mode.
     * @param  array<int, string>|null  $primaryShades  Hand-tuned 50–950 scale; generated from $primary when null.
     * @param  array<int, string>|null  $grayShades  Neutral scale; the Contiu greys when null.
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $description,
        public string $primary,
        public string $action,
        public string $navigation,
        public string $background,
        public string $actionDark,
        public string $navigationDark,
        public ?array $primaryShades = null,
        public ?array $grayShades = null,
        public bool $isCustom = false,
    ) {}

    /**
     * A palette a user made from three colours. Dark mode keeps the button colour, and keeps the
     * navigation colour unless it is a light one (then it becomes near-black).
     */
    public static function custom(string $id, string $label, string $primary, string $navigation, string $background): self
    {
        return new self(
            id: $id,
            label: $label,
            description: 'Criada por ti',
            primary: $primary,
            action: $primary,
            navigation: $navigation,
            background: $background,
            actionDark: $primary,
            navigationDark: self::isLight($navigation) ? '#171717' : $navigation,
            isCustom: true,
        );
    }

    /**
     * The colours Filament registers for this palette.
     *
     * @return array<string, array<int, string>>
     */
    public function filamentColors(): array
    {
        return [
            'primary' => $this->primaryShades ?? Color::hex($this->primary),
            'gray' => $this->grayShades ?? BrandPalette::gray(),
        ];
    }

    /**
     * CSS custom properties read by resources/css/filament/admin/theme.css.
     *
     * @return array<string, string>
     */
    public function cssVariables(bool $dark = false): array
    {
        $action = $dark ? $this->actionDark : $this->action;
        $navigation = $dark ? $this->navigationDark : $this->navigation;
        $onLightNavigation = self::isLight($navigation);
        // Translucent overlays on the navigation bar: dark ink on a light bar, white on a dark one.
        $ink = $onLightNavigation ? '17 17 17' : '255 255 255';

        $variables = [
            '--contiu-brand-green' => $this->primary,
            '--contiu-action-green' => $action,
            '--contiu-on-action' => self::textOn($action),
            '--contiu-chrome' => $navigation,
            '--chrome-text' => self::textOn($navigation),
            '--chrome-text-muted' => match (true) {
                $onLightNavigation => 'rgb(17 17 17 / 0.7)',
                $dark => 'rgb(250 250 250 / 0.68)',
                default => '#ffffff',
            },
            '--chrome-hover' => "rgb({$ink} / 0.08)",
            '--chrome-active' => "rgb({$ink} / ".($onLightNavigation ? '0.1' : '0.16').')',
            '--chrome-ring' => "rgb({$ink} / 0.12)",
        ];

        if (! $dark) {
            $variables['--contiu-canvas'] = $this->background;
        }

        return $variables;
    }

    /** Whether the navigation bar needs the black logo (it has a light background). */
    public function hasLightNavigation(bool $dark = false): bool
    {
        return self::isLight($dark ? $this->navigationDark : $this->navigation);
    }

    /** White or near-black, whichever reads better on the given "#rrggbb" background. */
    public static function textOn(string $background): string
    {
        return self::contrast(self::LIGHT_TEXT, $background) >= self::contrast(self::DARK_TEXT, $background)
            ? self::LIGHT_TEXT
            : self::DARK_TEXT;
    }

    public static function isLight(string $color): bool
    {
        return self::textOn($color) === self::DARK_TEXT;
    }

    /** WCAG 2 contrast ratio between two "#rrggbb" colours (1 to 21). */
    public static function contrast(string $first, string $second): float
    {
        $lighter = max(self::luminance($first), self::luminance($second));
        $darker = min(self::luminance($first), self::luminance($second));

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private static function luminance(string $color): float
    {
        $channels = array_map(
            function (string $pair): float {
                $value = hexdec($pair) / 255;

                return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            },
            str_split(ltrim($color, '#'), 2),
        );

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
