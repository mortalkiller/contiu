<?php

namespace App\Filament\Analysis\Widgets;

use App\Enums\CalendarEntryType;
use App\Support\ActivityCalendar;
use App\Support\CalendarEntry;
use App\Support\FinanceSettings;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Enums\FiltersLayout;
use Saade\FilamentFullCalendar\Data\EventData;
use Saade\FilamentFullCalendar\Data\FetchInfo;
use Saade\FilamentFullCalendar\Widgets\FullCalendarWidget;

/**
 * The "Calendário" page's calendar: read-only, in European Portuguese, month / week / list / year, with
 * the kinds of rows to show as coloured chips above it. The rows come from App\Support\ActivityCalendar.
 */
class ActivityCalendarWidget extends FullCalendarWidget
{
    protected FiltersLayout $filtersLayout = FiltersLayout::AboveContent;

    protected bool $hasDeferredFilters = false;

    protected bool $persistsFiltersInSession = false;

    protected int|array|null $filtersFormColumns = 1;

    /** @return list<EventData> */
    public function fetchEvents(FetchInfo $info): array
    {
        $calendar = new ActivityCalendar(app(FinanceSettings::class), CarbonImmutable::today());

        // FullCalendar's end is exclusive.
        return array_map(
            $this->toEvent(...),
            $calendar->entries($info->start, $info->end->subDay(), CalendarEntryType::cases()),
        );
    }

    public function filtersSchema(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.analysis.widgets.activity-calendar-types'),
        ]);
    }

    public function filtersResetAction(Action $action): Action
    {
        return $action->livewireClickHandlerEnabled(false)->alpineClickHandler(<<<'JS'
            $el.closest('.fi-fc-filters').querySelector('[data-calendar-types]')
                .dispatchEvent(new Event('contiu-calendar-reset'))
            JS);
    }

    /** @return array<string, string> */
    public function jsCallbacks(): array
    {
        return [
            'datesSet' => <<<'JS'
                ({ view }) => {
                    $el.closest('.fi-wi-widget').querySelector('[data-calendar-types]')
                        .dispatchEvent(new CustomEvent('contiu-calendar-ready', { detail: view.calendar }))
                }
                JS,
            'eventSourceSuccess' => <<<'JS'
                (events) => {
                    const unchecked = $el.closest('.fi-wi-widget')
                        .querySelectorAll('[data-calendar-types] input:not(:checked)')
                    const hiddenTypes = new Set(Array.from(unchecked, input => input.value))

                    return events.map(event => hiddenTypes.has(event.groupId)
                        ? { ...event, display: 'none' }
                        : event)
                }
                JS,
        ];
    }

    private function toEvent(CalendarEntry $entry): EventData
    {
        $event = EventData::make()
            ->id($entry->id)
            ->groupId($entry->type->value)
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

    public function getLocale(): string
    {
        return str_replace('_', '-', app()->getLocale());
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
                'dayGridMonth' => ['text' => __('Month')],
                'dayGridWeek' => ['text' => __('Week')],
                'listMonth' => ['text' => __('Agenda')],
                'multiMonthYear' => ['text' => __('Year')],
            ],
        ];
    }

    /** @return array<string, list<string|list<string>>> */
    protected function toolbarButtons(): array
    {
        return [
            // As in the mock: the month on the left; the way through it and the views on the right.
            'start' => ['title'],
            'end' => [['prev', 'next'], 'today', ['dayGridMonth', 'dayGridWeek', 'listMonth', 'multiMonthYear']],
        ];
    }
}
