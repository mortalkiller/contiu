<?php

namespace App\Filament\Analysis\Widgets;

use App\Enums\CalendarEntryType;
use App\Support\ActivityCalendar;
use App\Support\CalendarEntry;
use App\Support\FinanceSettings;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\CheckboxList;
use Filament\Schemas\Schema;
use Filament\Tables\Enums\FiltersLayout;
use Saade\FilamentFullCalendar\Data\EventData;
use Saade\FilamentFullCalendar\Data\FetchInfo;
use Saade\FilamentFullCalendar\Widgets\FullCalendarWidget;

/**
 * The "Calendário" page's calendar: read-only, in European Portuguese, month / list / year, with
 * the kinds of rows to show as checkboxes above it. The rows come from App\Support\ActivityCalendar.
 */
class ActivityCalendarWidget extends FullCalendarWidget
{
    protected FiltersLayout $filtersLayout = FiltersLayout::AboveContent;

    protected bool $hasDeferredFilters = false;

    /** One field (the checkboxes) across the whole width; the checkboxes make their own columns. */
    protected int|array|null $filtersFormColumns = 1;

    /** @return list<EventData> */
    public function fetchEvents(FetchInfo $info): array
    {
        $calendar = new ActivityCalendar(app(FinanceSettings::class), CarbonImmutable::today());

        // FullCalendar's end is exclusive.
        return array_map(
            $this->toEvent(...),
            $calendar->entries($info->start, $info->end->subDay(), $this->selectedTypes()),
        );
    }

    public function filtersSchema(Schema $schema): Schema
    {
        return $schema->components([
            // The plugin makes the filters live on blur (live(true) is "onBlur"); a tick should refetch.
            CheckboxList::make('types')
                ->live()
                ->hiddenLabel()
                ->options(CalendarEntryType::class)
                ->default(array_map(fn (CalendarEntryType $type) => $type->value, CalendarEntryType::cases()))
                ->columns(['default' => 2, 'md' => 3, 'xl' => 6]),
        ]);
    }

    private function toEvent(CalendarEntry $entry): EventData
    {
        $event = EventData::make()
            ->id($entry->id)
            ->title($entry->title)
            ->start($entry->date->toDateString())
            ->allDay()
            ->color(self::color($entry));

        if ($entry->url !== null) {
            $event->url($entry->url);
        }

        if ($entry->detail !== null) {
            $event->tooltip($entry->detail);
        }

        $className = match (true) {
            $entry->type->isProjected() => 'contiu-calendar-projected',
            $entry->status === 'done' => 'contiu-calendar-done',
            default => null,
        };

        return $className === null ? $event : $event->extraProperties(['className' => $className]);
    }

    /** A Filament colour name, so the event follows the panel palette in light and dark mode. */
    private static function color(CalendarEntry $entry): string
    {
        return match ($entry->type) {
            CalendarEntryType::Obligation => match ($entry->status) {
                'overdue' => 'danger',
                'done' => 'success',
                default => 'warning',
            },
            CalendarEntryType::Payment => 'primary',
            CalendarEntryType::Receivable => $entry->status === 'overdue' ? 'danger' : 'info',
            default => 'gray',
        };
    }

    /** @return list<CalendarEntryType> */
    private function selectedTypes(): array
    {
        $selected = $this->filters['types'] ?? null;

        if (! is_array($selected)) {
            return CalendarEntryType::cases();
        }

        return array_values(array_filter(array_map(fn (mixed $value) => CalendarEntryType::tryFrom((string) $value), $selected)));
    }

    public function getLocale(): string
    {
        return 'pt';
    }

    /** @return list<string> only FullCalendar's free plugins */
    public function getPlugins(): array
    {
        return ['interaction', 'dayGrid', 'list', 'multiMonth'];
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return [
            'initialView' => 'dayGridMonth',
            'mobileInitialView' => 'listMonth',
            'firstDay' => 1,
            'buttons' => [
                'dayGridMonth' => ['text' => 'Mês'],
                'listMonth' => ['text' => 'Agenda'],
                'multiMonthYear' => ['text' => 'Ano'],
            ],
        ];
    }

    /** @return array<string, list<string|list<string>>> */
    protected function toolbarButtons(): array
    {
        return [
            'start' => [['prev', 'next'], 'today'],
            'center' => ['title'],
            'end' => [['dayGridMonth', 'listMonth', 'multiMonthYear']],
        ];
    }
}
