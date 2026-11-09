<?php

namespace App\Filament\Analysis\Widgets;

use Saade\FilamentFullCalendar\Data\FetchInfo;
use Saade\FilamentFullCalendar\Widgets\FullCalendarWidget;

/**
 * The "Calendário" page's calendar: read-only, in European Portuguese, month / list / year.
 */
class ActivityCalendarWidget extends FullCalendarWidget
{
    public function fetchEvents(FetchInfo $info): array
    {
        return [];
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
