<?php

namespace App\Filament\Support;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Enums\IconPosition;
use Illuminate\Support\Str;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * The period a page shows ("2026", "Jul. a set. de 2026"), as a button in the page header that
 * opens the list of periods, as in the mocks. Choosing one either runs a closure on the page
 * (a Livewire property) or opens its URL (a page that reads the period from the query).
 */
final class PeriodPicker
{
    /**
     * @param  array<int|string, string>  $options  value => label, in the order to list them
     * @param  Closure(int|string): void  $select
     */
    public static function make(string $name, array $options, int|string|null $current, Closure $select, ?string $label = null): ActionGroup
    {
        return self::group($name, $options, $current, $label, fn (Action $action, int|string $value): Action => $action->action(fn () => $select($value)));
    }

    /**
     * @param  array<int|string, string>  $options  value => label, in the order to list them
     * @param  Closure(int|string): string  $url
     */
    public static function links(string $name, array $options, int|string|null $current, Closure $url, ?string $label = null): ActionGroup
    {
        return self::group($name, $options, $current, $label, fn (Action $action, int|string $value): Action => $action->url($url($value)));
    }

    /**
     * @param  array<int|string, string>  $options
     * @param  Closure(Action, int|string): Action  $configure
     */
    private static function group(string $name, array $options, int|string|null $current, ?string $label, Closure $configure): ActionGroup
    {
        $actions = [];

        foreach ($options as $value => $optionLabel) {
            $actions[] = $configure(
                Action::make($name.'_'.Str::slug((string) $value, '_'))
                    ->label($optionLabel)
                    ->color((string) $value === (string) $current ? 'primary' : 'gray'),
                $value,
            );
        }

        return ActionGroup::make($actions)
            ->label($options[$current] ?? (string) $current)
            ->tooltip($label)
            ->button()
            ->color('gray')
            ->icon(Phosphor::CaretDown)
            ->iconPosition(IconPosition::After)
            ->dropdownPlacement('bottom-end');
    }
}
