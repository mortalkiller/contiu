<?php

namespace App\Support\Appearance;

use App\Filament\Support\BrandPalette;
use App\Models\User;
use App\Models\UserPalette;
use Filament\Support\Colors\Color;

/**
 * Every palette a user can pick in Perfil → Aparência. To add one, add an entry to builtIn().
 */
final class PaletteRegistry
{
    public const string DEFAULT = 'contiu';

    /** users.palette holds "custom:{id}" for a palette the user made. */
    public const string CUSTOM_PREFIX = 'custom:';

    /**
     * @return array<string, Palette>
     */
    public static function builtIn(): array
    {
        $palettes = [
            new Palette(
                id: self::DEFAULT,
                label: 'Contiu',
                description: 'Verde de origem',
                primary: BrandPalette::PRIMARY,
                action: BrandPalette::ACTION_GREEN,
                navigation: BrandPalette::ACTION_GREEN,
                background: '#e5f3f1',
                actionDark: BrandPalette::ACTION_GREEN,
                navigationDark: BrandPalette::ACTION_GREEN,
                primaryShades: BrandPalette::primary(),
            ),
            new Palette(
                id: 'clean-saas-a',
                label: 'Clean SaaS A',
                description: 'Barra escura, ação azul',
                primary: '#2563eb',
                action: '#2563eb',
                navigation: '#111827',
                background: '#f3f4f6',
                actionDark: '#2563eb',
                navigationDark: '#0b0f19',
                grayShades: Color::Gray,
            ),
            new Palette(
                id: 'clean-saas-b',
                label: 'Clean SaaS B',
                description: 'Barra azul',
                primary: '#2563eb',
                action: '#2563eb',
                navigation: '#1e40af',
                background: '#f3f4f6',
                actionDark: '#2563eb',
                navigationDark: '#1e40af',
                grayShades: Color::Gray,
            ),
            new Palette(
                id: 'clean-saas-c',
                label: 'Clean SaaS C',
                description: 'Barra clara',
                primary: '#2563eb',
                action: '#2563eb',
                navigation: '#ffffff',
                background: '#f3f4f6',
                actionDark: '#2563eb',
                navigationDark: '#111827',
                grayShades: Color::Gray,
            ),
            new Palette(
                id: 'clean-saas-d',
                label: 'Clean SaaS D',
                description: 'Ação escura',
                primary: '#2563eb',
                action: '#111827',
                navigation: '#111827',
                background: '#f3f4f6',
                actionDark: '#f3f4f6',
                navigationDark: '#0b0f19',
                grayShades: Color::Gray,
            ),
            new Palette(
                id: 'soft-finance-a',
                label: 'Soft Finance A',
                description: 'Verde profundo',
                primary: '#3fa66b',
                action: '#12372a',
                navigation: '#12372a',
                background: '#e8f7ef',
                actionDark: '#e8f7ef',
                navigationDark: '#0a1f17',
            ),
            new Palette(
                id: 'soft-finance-b',
                label: 'Soft Finance B',
                description: 'Verde vivo',
                primary: '#3fa66b',
                action: '#3fa66b',
                navigation: '#12372a',
                background: '#fafbf7',
                actionDark: '#3fa66b',
                navigationDark: '#0a1f17',
            ),
            new Palette(
                id: 'soft-finance-c',
                label: 'Soft Finance C',
                description: 'Barra clara',
                primary: '#3fa66b',
                action: '#12372a',
                navigation: '#e8f7ef',
                background: '#fafbf7',
                actionDark: '#e8f7ef',
                navigationDark: '#12372a',
            ),
        ];

        return array_combine(array_map(fn (Palette $palette): string => $palette->id, $palettes), $palettes);
    }

    public static function default(): Palette
    {
        return self::builtIn()[self::DEFAULT];
    }

    /**
     * The palette stored under $key, as far as $user may use it: any built-in one, or a custom
     * one that belongs to them.
     */
    public static function find(?string $key, User $user): ?Palette
    {
        if ($key === null) {
            return null;
        }

        if (! str_starts_with($key, self::CUSTOM_PREFIX)) {
            return self::builtIn()[$key] ?? null;
        }

        return UserPalette::query()
            ->whereBelongsTo($user)
            ->find((int) substr($key, strlen(self::CUSTOM_PREFIX)))
            ?->toPalette();
    }

    /** The user's chosen palette; Contiu for guests, no choice, or a choice that no longer exists. */
    public static function forUser(?User $user): Palette
    {
        if ($user === null) {
            return self::default();
        }

        return self::find($user->palette, $user) ?? self::default();
    }

    public static function current(): Palette
    {
        $user = auth()->user();

        return self::forUser($user instanceof User ? $user : null);
    }
}
