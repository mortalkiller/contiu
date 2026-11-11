<?php

namespace App\Filament\Support;

use App\Enums\Language;
use Carbon\CarbonInterface;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;

/**
 * The pieces every page of one record is built from, as in the mocks: the title with its state
 * badges, the cells of small-caps labels, the side cards that are a short list of rows and the
 * way a date is written. One place, so a fiscal document, a payment and an expense read alike.
 */
final class DetailPage
{
    /**
     * "Fatura FT 2026/15" followed by its badges, as HTML for a page header. Every value is escaped.
     *
     * @param  list<array{label: string, color: string}>  $badges
     */
    public static function titleWithBadges(string $title, array $badges): string
    {
        $rendered = Blade::render(<<<'BLADE'
            @foreach ($badges as $badge)
                <x-filament::badge :color="$badge['color']">{{ $badge['label'] }}</x-filament::badge>
            @endforeach
            BLADE, ['badges' => $badges]);

        return '<span class="flex flex-wrap items-center gap-x-3 gap-y-1"><span>'.e($title).'</span>'.$rendered.'</span>';
    }

    /** One labelled value of the record, with a dash while it has none. Small capitals come from `fap-caps-labels` on the grid. */
    public static function cell(string $name, string $label, ?string $state): TextEntry
    {
        return TextEntry::make($name)->label($label)->state($state)->placeholder('—');
    }

    /** A muted sentence inside a card, explaining what is (or is not yet) there. */
    public static function help(string $name, string $text): TextEntry
    {
        return TextEntry::make($name)->hiddenLabel()->inlineLabel(false)->color('gray')->state($text);
    }

    /**
     * A side card that is a short list, or nothing when the list is empty.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function rowsCard(string $heading, string $name, array $rows): ?Section
    {
        return $rows === [] ? null : Section::make($heading)->schema(self::rows($name, $rows))->extraAttributes(['class' => 'fap-divided']);
    }

    /**
     * The rows of a side card, as in the mocks: a title (a link when it leads somewhere) over a
     * muted line, and at the end an amount over its state.
     *
     * @param  list<array<string, mixed>>  $rows  each with title and meta, and optionally url, amount, badge (label and color) and color (of the meta)
     * @return list<Flex>
     */
    public static function rows(string $name, array $rows): array
    {
        return Collection::make($rows)->map(function (array $row, int $index) use ($name): Flex {
            $key = "{$name}_{$index}";
            $url = $row['url'] ?? null;
            $badge = $row['badge'] ?? null;
            $aside = array_values(array_filter([
                isset($row['amount']) ? TextEntry::make("{$key}_amount")->hiddenLabel()->state($row['amount'])->alignEnd() : null,
                $badge === null ? null : TextEntry::make("{$key}_state")->hiddenLabel()->state($badge['label'])->badge()->color($badge['color'])->alignEnd(),
            ]));

            return Flex::make(array_values(array_filter([
                Group::make(array_values(array_filter([
                    TextEntry::make("{$key}_title")->hiddenLabel()->state($row['title'])->weight('medium')->url($url)->color($url === null ? null : 'primary'),
                    blank($row['meta'] ?? null) ? null : TextEntry::make("{$key}_meta")->hiddenLabel()->state($row['meta'])->color($row['color'] ?? 'gray'),
                ])))->gap(false),
                $aside === [] ? null : Group::make($aside)->gap(false)->grow(false),
            ])));
        })->all();
    }

    public static function dateFormat(): string
    {
        return Language::resolve(app()->getLocale())->dateFormat();
    }

    public static function date(CarbonInterface $date): string
    {
        return $date->format(self::dateFormat());
    }

    /** "12/11/2026 às 10:42". */
    public static function moment(CarbonInterface $at): string
    {
        return __(':date at :time', ['date' => self::date($at), 'time' => $at->format('H:i')]);
    }
}
