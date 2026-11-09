<?php

use App\Enums\CalendarEntryType;
use App\Filament\Analysis\Widgets\ActivityCalendarWidget;
use App\Filament\Pages\Calendar;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\Retainer;
use App\Models\Supplier;
use App\Models\TaxObligationCompletion;
use App\Support\FinanceSettings;
use App\Support\Money;
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

/** The events the widget gives FullCalendar for November 2026. */
function novemberEvents(array $types = []): array
{
    $widget = Livewire::test(ActivityCalendarWidget::class);

    if ($types !== []) {
        $widget->set('filters.types', $types);
    }

    return $widget->getCalendarEvents('2026-10-26', '2026-12-07');
}

function eventTitled(array $events, string $start): array
{
    return collect($events)->first(fn (array $event) => str_starts_with($event['title'], $start)) ?? [];
}

it('shows the account events as whole-day events with their colours', function () {
    $expense = Expense::factory()->for(Supplier::factory()->create(['name' => 'Coworking']))
        ->create(['spent_on' => '2026-11-12', 'is_paid' => true, 'net_amount' => 15000, 'vat_rate' => 0]);
    Payment::factory()->create(['paid_on' => '2026-11-03']);
    Receivable::factory()->create(['due_on' => '2026-11-15', 'description' => 'Fatura 23']);
    Receivable::factory()->create(['due_on' => '2026-11-02', 'description' => 'Fatura 20']);

    $events = novemberEvents();

    expect(eventTitled($events, 'Coworking'))->toMatchArray([
        'title' => 'Coworking · '.Money::format(15000),
        'start' => '2026-11-12',
        'allDay' => true,
        'color' => 'var(--gray-500)',
        'url' => ExpenseResource::getUrl('edit', ['record' => $expense]),
    ])
        ->and(eventTitled($events, 'Recebido')['color'])->toBe('var(--primary-500)')
        ->and(collect($events)->first(fn (array $event) => str_contains($event['title'], 'Fatura 23'))['color'])->toBe('var(--info-500)')
        ->and(collect($events)->first(fn (array $event) => str_contains($event['title'], 'Fatura 20'))['color'])->toBe('var(--danger-500)');
});

it('colours obligations by status and marks projected and done events', function () {
    $this->account->forceFill(['created_at' => '2026-10-01', 'billing_exempt' => true])->save();
    app(FinanceSettings::class)->set(['activity_started_on' => '2024-01-01', 'activity_start_kind' => 'first']);
    TaxObligationCompletion::factory()->create(['key' => 'ss:2026-11']);
    $retainer = Retainer::factory()->create(['due_day' => 25, 'starts_on' => '2026-11-01']);

    $events = novemberEvents();
    $byId = collect($events)->keyBy('id');

    // The declaration was due on 31 October (in view: the grid starts on Monday 26).
    expect($byId['ss-declaration:2026-Q3'])->toMatchArray(['color' => 'var(--danger-500)'])
        ->and($byId['ss:2026-11'])->toMatchArray(['color' => 'var(--success-500)'])
        ->and($byId['ss:2026-11']['className'])->toContain('contiu-calendar-done')
        ->and($byId['ss:2026-11']['extendedProps']['tooltip'])->toContain('Pagar entre 10 e 20 de novembro')
        ->and($byId['retainer:'.$retainer->getKey().':2026-11-25'])->toMatchArray(['color' => 'var(--gray-500)'])
        ->and($byId['retainer:'.$retainer->getKey().':2026-11-25']['className'])->toContain('contiu-calendar-projected');
});

it('shows only the types left on in the filter', function () {
    Expense::factory()->create(['spent_on' => '2026-11-12']);
    Payment::factory()->create(['paid_on' => '2026-11-12']);

    expect(novemberEvents())->toHaveCount(2)
        ->and(novemberEvents([CalendarEntryType::Payment->value]))->toHaveCount(1)
        ->and(novemberEvents([CalendarEntryType::Payment->value])[0]['title'])->toStartWith('Recebido');
});

it('offers every type in the filter, all on by default', function () {
    Livewire::test(ActivityCalendarWidget::class)
        ->assertSet('filters.types', array_map(fn (CalendarEntryType $type) => $type->value, CalendarEntryType::cases()))
        ->assertSee('Obrigações fiscais')
        ->assertSee('Recorrentes previstas');
});

it('refetches as soon as a type is ticked, not on blur', function () {
    Livewire::test(ActivityCalendarWidget::class)
        ->assertSeeHtml('wire:model.live="filters.types"')
        ->assertDontSeeHtml('wire:model.live.blur="filters.types"');
});
