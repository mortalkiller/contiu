<?php

use App\Filament\Analysis\Widgets\ActivityCalendarWidget;
use App\Filament\Pages\Calendar;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-09'));
    actingAsAdmin();
});

it('opens the calendar page in the Análise group', function () {
    $this->get(Calendar::getUrl())->assertOk()->assertSee('Calendário');

    expect(Calendar::getNavigationGroup())->toBe('Análise');
});

it('shows only Phosphor icons on the calendar page and its toolbar', function () {
    $page = $this->get(Calendar::getUrl())->assertOk()->getContent();
    $calendar = Livewire::test(ActivityCalendarWidget::class)->assertSeeHtml('fi-fc-toolbar')->html();

    expect(heroiconsOn($page))->toBe(0)
        ->and(heroiconsOn($calendar))->toBe(0);
});

it('uses European Portuguese, Monday first and the month, list and year views', function () {
    $widget = Livewire::test(ActivityCalendarWidget::class)->instance();

    expect($widget->getLocale())->toBe('pt')
        ->and($widget->config())->toMatchArray(['initialView' => 'dayGridMonth', 'firstDay' => 1])
        ->and($widget->getPlugins())->toEqualCanonicalizing(['interaction', 'dayGrid', 'list', 'multiMonth']);
});
