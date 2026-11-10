<?php

use App\Enums\CalendarEntryType;
use App\Filament\Analysis\Widgets\ActivityCalendarWidget;
use App\Filament\Analysis\Widgets\MonthAhead;
use App\Filament\Analysis\Widgets\UpcomingDeadlines;
use App\Filament\Analysis\Widgets\WeekAhead;
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

it('opens the calendar page from the top of the menu, outside any group', function () {
    $this->get(Calendar::getUrl())->assertOk()->assertSee('Calendário');

    expect(Calendar::getNavigationGroup())->toBeNull();
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

it('lists the deadlines with the overdue ones first', function () {
    $this->account->forceFill(['created_at' => '2026-10-01', 'billing_exempt' => true])->save();
    app(FinanceSettings::class)->set(['activity_started_on' => '2024-01-01', 'activity_start_kind' => 'first']);

    Livewire::test(UpcomingDeadlines::class)
        ->assertSeeInOrder([
            'Prazos a seguir',
            'Segurança Social · outubro', '10 a 20 out.', 'em atraso',
            'Declaração trimestral da Segurança Social', 'até 31 out.', 'em atraso',
            'Segurança Social · novembro', Money::format(2000), '10 a 20 nov.', '11 dias',
            'Declaração trimestral da Segurança Social', 'até 31 jan.',
        ]);
});

it('says when nothing is pending', function () {
    Livewire::test(UpcomingDeadlines::class)
        ->assertSee('Prazos a seguir')
        ->assertSee('Sem prazos por cumprir');
});

it('shows the column beside the calendar on the page', function () {
    $this->get(Calendar::getUrl())->assertOk()->assertSeeLivewire(UpcomingDeadlines::class);
});

it('offers a week view between the month and the list', function () {
    $widget = Livewire::test(ActivityCalendarWidget::class)->instance();

    expect($widget->config()['buttons'])->toHaveKey('dayGridWeek')
        ->and($widget->config()['buttons']['dayGridWeek'])->toBe(['text' => 'Semana']);

    Livewire::test(ActivityCalendarWidget::class)->assertSeeHtml('dayGridWeek');
});

it('adds up what is still to come in and go out until the end of the month', function () {
    Receivable::factory()->create(['amount' => 40000, 'due_on' => '2026-11-15']);
    Receivable::factory()->create(['amount' => 99900, 'due_on' => '2026-12-02']); // next month
    $paid = Receivable::factory()->create(['amount' => 5000, 'due_on' => '2026-11-20']);
    Payment::factory()->for($paid)->create(['amount' => 5000, 'paid_on' => '2026-11-09']);
    Expense::factory()->unpaid()->create(['spent_on' => '2026-11-10', 'net_amount' => 6150, 'vat_rate' => 0]);
    Expense::factory()->create(['spent_on' => '2026-11-12', 'net_amount' => 2000]); // already paid

    Livewire::test(MonthAhead::class)
        ->assertSee('O resto de')
        ->assertSeeInOrder(['Ainda vai entrar', Money::format(40000)])
        ->assertSeeInOrder(['Ainda vai sair', Money::format(6150)])
        ->assertSeeInOrder(['Diferença prevista', Money::format(33850)]);
});

it('shows the rest of the month beside the calendar', function () {
    $this->get(Calendar::getUrl())->assertOk()->assertSeeLivewire(MonthAhead::class);
});

it('sums up the current week beside the calendar: in, out, deadlines and what is late', function () {
    $this->travelTo(Carbon::parse('2026-11-10')); // Tuesday; the week is 9 to 15 November
    actingAsAdmin();
    Payment::factory()->create(['amount' => 40000, 'paid_on' => '2026-11-12']);
    Receivable::factory()->create(['amount' => 30000, 'due_on' => '2026-11-15']);
    Receivable::factory()->create(['amount' => 18000, 'due_on' => '2026-10-28']); // late, from before
    Expense::factory()->unpaid()->create(['spent_on' => '2026-11-10', 'net_amount' => 5000, 'vat_rate' => 0.23]);
    Expense::factory()->create(['spent_on' => '2026-11-10', 'net_amount' => 2000, 'vat_rate' => 0]);
    Expense::factory()->unpaid()->create(['spent_on' => '2026-11-20', 'net_amount' => 99900]); // next week

    Livewire::test(WeekAhead::class)
        ->assertSee('9 a 15 de novembro')
        ->assertSeeInOrder(['Entra esta semana', Money::format(40000).' já recebidos'])
        ->assertSeeInOrder(['Sai esta semana', Money::format(6150).' por pagar', Money::format(8150)])
        ->assertSeeInOrder(['Em atraso de semanas anteriores', '1']);

    $this->get(Calendar::getUrl())->assertOk()->assertSeeLivewire(WeekAhead::class);
});
