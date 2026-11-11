<?php

use App\Enums\CalendarEntryType;
use App\Filament\Analysis\Widgets\ActivityCalendarWidget;
use App\Filament\Analysis\Widgets\MonthAhead;
use App\Filament\Analysis\Widgets\UpcomingDeadlines;
use App\Filament\Analysis\Widgets\WeekAhead;
use App\Filament\Pages\Calendar;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Account;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\Retainer;
use App\Models\Supplier;
use App\Models\TaxObligationCompletion;
use App\Support\CurrentAccount;
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
})->group('ui');

it('shows only Phosphor icons on the calendar page and its toolbar', function () {
    $page = $this->get(Calendar::getUrl())->assertOk()->getContent();
    $calendar = Livewire::test(ActivityCalendarWidget::class)->assertSeeHtml('fi-fc-toolbar')->html();

    expect(heroiconsOn($page))->toBe(0)
        ->and(heroiconsOn($calendar))->toBe(0);
})->group('ui');

it('uses European Portuguese, Monday first and the month, list and year views', function () {
    app()->setLocale('pt_PT');
    $widget = Livewire::test(ActivityCalendarWidget::class)->instance();

    expect($widget->getLocale())->toBe('pt-PT')
        ->and($widget->config())->toMatchArray(['initialView' => 'dayGridMonth', 'firstDay' => 1])
        ->and($widget->getPlugins())->toEqualCanonicalizing(['interaction', 'dayGrid', 'list', 'multiMonth']);
})->group('ui');

it('uses the regional English locale for the calendar controls', function () {
    app()->setLocale('en_US');
    $widget = Livewire::test(ActivityCalendarWidget::class)->instance();

    expect($widget->getLocale())->toBe('en-US')
        ->and($widget->config()['firstDay'])->toBe(1)
        ->and(CalendarEntryType::Obligation->getLabel())->toBe('Tax obligations');
})->group('ui');

/** The events the widget gives FullCalendar for November 2026. */
function novemberEvents(): array
{
    return Livewire::test(ActivityCalendarWidget::class)->getCalendarEvents('2026-10-26', '2026-12-07');
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
        'url' => ExpenseResource::getUrl('view', ['record' => $expense]),
    ])
        ->and(eventTitled($events, 'Recebido')['color'])->toBe('var(--primary-500)')
        ->and(collect($events)->first(fn (array $event) => str_contains($event['title'], 'Fatura 23'))['color'])->toBe('var(--info-500)')
        ->and(collect($events)->first(fn (array $event) => str_contains($event['title'], 'Fatura 20'))['color'])->toBe('var(--danger-500)');
})->group('integration');

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
})->group('integration');

it('supplies the browser with typed events for the requested range and account', function () {
    $expense = Expense::factory()->create(['spent_on' => '2026-11-12']);
    $receivable = Receivable::factory()->create(['due_on' => '2027-01-15']);
    $payment = Payment::factory()->for($receivable)->create(['paid_on' => '2026-11-12']);
    $decemberPayment = Payment::factory()->for($receivable)->create(['paid_on' => '2026-12-07']);
    CurrentAccount::run(Account::factory()->create(), fn () => Payment::factory()->create(['paid_on' => '2026-11-12']));

    $widget = Livewire::test(ActivityCalendarWidget::class);
    $november = collect($widget->getCalendarEvents('2026-10-26', '2026-12-07'))->keyBy('id');
    $december = collect($widget->getCalendarEvents('2026-12-07', '2026-12-14'))->keyBy('id');

    expect($november->keys()->all())->toEqualCanonicalizing(['expense:'.$expense->id, 'payment:'.$payment->id])
        ->and($november['expense:'.$expense->id]['groupId'] ?? null)->toBe(CalendarEntryType::Expense->value)
        ->and($november['payment:'.$payment->id]['groupId'] ?? null)->toBe(CalendarEntryType::Payment->value)
        ->and($december->keys()->all())->toBe(['payment:'.$decemberPayment->id]);
})->group('integration');

it('offers every type in the filter, all on by default', function () {
    $widget = Livewire::test(ActivityCalendarWidget::class)
        ->assertSee('Obrigações fiscais')
        ->assertSee('Recorrentes previstas');

    foreach (CalendarEntryType::cases() as $type) {
        $widget->assertSeeHtml('value="'.$type->value.'" checked');
    }

    $widget->assertDontSeeHtml('wire:model.live="filters.types"');
})->group('ui');

it('lists the deadlines with the overdue ones first', function () {
    $this->account->forceFill(['created_at' => '2026-10-01', 'billing_exempt' => true])->save();
    app(FinanceSettings::class)->set(['activity_started_on' => '2024-01-01', 'activity_start_kind' => 'first', 'vat_exempt' => true]);

    // 31 October 2026 and 31 January 2027 fall on a weekend: the declarations move to Monday.
    Livewire::test(UpcomingDeadlines::class)
        ->assertSeeInOrder([
            'Prazos a seguir',
            'Segurança Social · outubro', '10 a 20 out.', 'em atraso',
            'Declaração trimestral da Segurança Social', 'até 2 nov.', 'em atraso',
            'Segurança Social · novembro', Money::format(2000), '10 a 20 nov.', '11 dias',
            'Declaração trimestral da Segurança Social', 'até 1 fev.',
        ]);
})->group('ui');

it('says when nothing is pending', function () {
    Livewire::test(UpcomingDeadlines::class)
        ->assertSee('Prazos a seguir')
        ->assertSee('Sem prazos por cumprir');
})->group('ui');

it('shows the column beside the calendar on the page', function () {
    $this->get(Calendar::getUrl())->assertOk()->assertSeeLivewire(UpcomingDeadlines::class);
})->group('ui');

it('offers a week view between the month and the list', function () {
    $widget = Livewire::test(ActivityCalendarWidget::class)->instance();

    expect($widget->config()['buttons'])->toHaveKey('dayGridWeek')
        ->and($widget->config()['buttons']['dayGridWeek'])->toBe(['text' => 'Semana']);

    Livewire::test(ActivityCalendarWidget::class)->assertSeeHtml('dayGridWeek');
})->group('ui');

it('adds up what is still to come in and go out until the end of the month', function () {
    Receivable::factory()->create(['amount' => 40000, 'due_on' => '2026-11-15']);
    Receivable::factory()->create(['amount' => 99900, 'due_on' => '2026-12-02']); // next month
    $paid = Receivable::factory()->create(['amount' => 5000, 'due_on' => '2026-11-20']);
    Payment::factory()->for($paid)->create(['amount' => 5000, 'paid_on' => '2026-11-09']);
    Expense::factory()->unpaid()->create(['spent_on' => '2026-11-10', 'net_amount' => 6150, 'vat_rate' => 0]);
    Expense::factory()->create(['spent_on' => '2026-11-12', 'net_amount' => 2000]); // already paid

    Livewire::test(MonthAhead::class)
        ->assertSee('Novembro em números')
        ->assertSeeInOrder(['Ainda vai entrar', Money::format(40000)])
        ->assertSeeInOrder(['Ainda vai sair', Money::format(6150)])
        ->assertSeeInOrder(['Diferença prevista', Money::format(33850)]);
})->group('ui');

it('shows the rest of the month beside the calendar', function () {
    $this->get(Calendar::getUrl())->assertOk()->assertSeeLivewire(MonthAhead::class);
})->group('ui');

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
})->group('ui');
