<?php

namespace App\Filament\Support;

use Filament\Support\Colors\Color;
use MortalKiller\FilamentAppearance\Palette;

/**
 * The palettes a user can pick in Perfil → Aparência (mortalkiller/filament-appearance).
 * To add one, add an entry to all().
 */
final class ContiuPalettes
{
    public const string DEFAULT = 'contiu';

    /**
     * @return list<Palette>
     */
    public static function all(): array
    {
        $translate = fn (string $text): string => app()->bound('translator') ? __($text) : $text;

        return [
            // The Contiu green is dark in both modes, so the white logo is used in both.
            Palette::make(self::DEFAULT)
                ->label($translate('Contiu'))
                ->description($translate('Original green'))
                ->primary(BrandPalette::PRIMARY)
                ->primaryShades(BrandPalette::primary())
                ->action(BrandPalette::ACTION_GREEN)
                ->navigation(BrandPalette::ACTION_GREEN)
                ->background('#e5f3f1')
                ->brandLogo(fn (): string => asset('brand/contiu/contiu-logo-white.svg')),
            Palette::make('clean-saas-a')
                ->label($translate('Clean SaaS A'))
                ->description($translate('Dark bar, blue action'))
                ->primary('#2563eb')
                ->navigation('#111827', dark: '#0b0f19')
                ->background('#f3f4f6')
                ->grayShades(Color::Gray),
            Palette::make('clean-saas-b')
                ->label($translate('Clean SaaS B'))
                ->description($translate('Blue bar'))
                ->primary('#2563eb')
                ->navigation('#1e40af')
                ->background('#f3f4f6')
                ->grayShades(Color::Gray),
            Palette::make('clean-saas-c')
                ->label($translate('Clean SaaS C'))
                ->description($translate('Light bar'))
                ->primary('#2563eb')
                ->navigation('#ffffff', dark: '#111827')
                ->background('#f3f4f6')
                ->grayShades(Color::Gray),
            Palette::make('clean-saas-d')
                ->label($translate('Clean SaaS D'))
                ->description($translate('Dark action'))
                ->primary('#2563eb')
                ->action('#111827', dark: '#f3f4f6')
                ->navigation('#111827', dark: '#0b0f19')
                ->background('#f3f4f6')
                ->grayShades(Color::Gray),
            Palette::make('soft-finance-a')
                ->label($translate('Soft Finance A'))
                ->description($translate('Deep green'))
                ->primary('#3fa66b')
                ->action('#12372a', dark: '#e8f7ef')
                ->navigation('#12372a', dark: '#0a1f17')
                ->background('#e8f7ef'),
            Palette::make('soft-finance-b')
                ->label($translate('Soft Finance B'))
                ->description($translate('Bright green'))
                ->primary('#3fa66b')
                ->navigation('#12372a', dark: '#0a1f17')
                ->background('#fafbf7'),
            Palette::make('soft-finance-c')
                ->label($translate('Soft Finance C'))
                ->description($translate('Light bar'))
                ->primary('#3fa66b')
                ->action('#12372a', dark: '#e8f7ef')
                ->navigation('#e8f7ef', dark: '#12372a')
                ->background('#fafbf7'),
            // Night, from the public site: indigo buttons on a lavender canvas and a night-blue bar
            // with an indigo and a fluorescent green glow. In dark mode the buttons turn lavender.
            Palette::make('night')
                ->label($translate('Night'))
                ->description($translate('Indigo with a green glow'))
                ->primary('#5a3df0')
                ->action('#5a3df0', dark: '#a5acff')
                ->navigation('#0c0e1d', dark: '#080918')
                ->navigationGlow('#19f5a0', '#5a3df0')
                ->background('#eeeffc')
                ->grayShades(Color::Slate),
            // Black and white: black bar and buttons on white, neutral greys, no colour but the
            // states (success, warning, danger). In dark mode the buttons turn near-white.
            Palette::make('black-white')
                ->label($translate('Black and white'))
                ->description($translate('Black bar and buttons'))
                ->primary('#171717')
                ->primaryShades(Color::Neutral)
                ->action('#0a0a0a', dark: '#fafafa')
                ->navigation('#0a0a0a', dark: '#000000')
                ->background('#f5f5f5')
                ->grayShades(Color::Neutral),
        ];
    }
}
