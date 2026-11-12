<?php

namespace App\Filament\Support;

use App\Support\ActivityCatalogue;
use App\Support\FinanceSettings;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;

/**
 * The CAE-Rev.4 fields (ActivityCatalogue): the main code and the secondary ones, as on the
 * declaration of activity. Searched by number or name; $kind (the sign-up's kind of activity)
 * puts its divisions first.
 */
final class CaeSelect
{
    /** Secondary codes one account can keep. */
    public const MAX_SECONDARY = 20;

    /**
     * The main CAE. With $keepsStored (the settings page) the code already saved stays valid even
     * when it is not in CAE-Rev.4, such as a CAE-Rev.3 code from before the revision.
     *
     * @param  (Closure(Get): ?string)|null  $kind
     */
    public static function main(string $name = 'cae', ?Closure $kind = null, bool $keepsStored = false): Select
    {
        return Select::make($name)
            ->searchable()
            ->options(fn (Get $get): array => ActivityCatalogue::searchCae('', $kind?->__invoke($get), limit: 30))
            ->getSearchResultsUsing(fn (string $search, Get $get): array => ActivityCatalogue::searchCae($search, $kind?->__invoke($get)))
            ->getOptionLabelUsing(fn (?string $value): ?string => ActivityCatalogue::caeLabel($value) ?? $value)
            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($keepsStored): void {
                $code = trim((string) $value);

                if ($code === '' || ActivityCatalogue::caeExists($code) || ($keepsStored && $code === app(FinanceSettings::class)->cae())) {
                    return;
                }

                $fail(__('Choose a CAE from the list.'));
            });
    }

    /**
     * The secondary CAEs: any number up to MAX_SECONDARY, never the main one ($mainName).
     *
     * @param  (Closure(Get): ?string)|null  $kind
     */
    public static function secondary(string $name = 'secondary_caes', string $mainName = 'cae', ?Closure $kind = null): Select
    {
        $withoutMain = fn (array $options, Get $get): array => array_diff_key($options, [(string) $get($mainName) => true]);

        return Select::make($name)
            ->multiple()
            ->searchable()
            ->maxItems(self::MAX_SECONDARY)
            ->options(fn (Get $get): array => $withoutMain(ActivityCatalogue::searchCae('', $kind?->__invoke($get), limit: 30), $get))
            ->getSearchResultsUsing(fn (string $search, Get $get): array => $withoutMain(ActivityCatalogue::searchCae($search, $kind?->__invoke($get)), $get))
            ->getOptionLabelsUsing(fn (array $values): array => collect($values)->mapWithKeys(fn (string $code): array => [$code => ActivityCatalogue::caeLabel($code) ?? $code])->all())
            ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $mainName): void {
                $codes = array_map(fn (mixed $code): string => trim((string) $code), (array) $value);

                if (in_array(trim((string) $get($mainName)), $codes, true)) {
                    $fail(__('The main CAE does not go among the secondary ones.'));
                } elseif (collect($codes)->contains(fn (string $code): bool => ! ActivityCatalogue::caeExists($code))) {
                    $fail(__('Choose a CAE from the list.'));
                }
            });
    }
}
