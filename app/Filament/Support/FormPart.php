<?php

namespace App\Filament\Support;

use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;

/**
 * One block of a create or edit form, as in the mocks: a card when the form is a page, and a
 * titled block under a hairline when the same form opens in a modal (filament-appearance
 * `fap-part`), so a modal does not stack cards inside its own card.
 */
final class FormPart
{
    public static function make(?string $heading = null): Section
    {
        return Section::make($heading)
            ->contained(fn (Section $component): bool => ! self::inModal($component))
            ->extraAttributes(fn (Section $component): array => self::inModal($component) ? ['class' => 'fap-part'] : []);
    }

    /** Whether the component belongs to the form of an action's modal rather than to a page. */
    public static function inModal(Component $component): bool
    {
        return str_starts_with((string) $component->getRootContainer()->getKey(), 'mountedActionSchema');
    }
}
