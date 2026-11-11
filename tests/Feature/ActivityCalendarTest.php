<?php

use App\Enums\CalendarEntryType;
use App\Enums\ProjectStatus;
use App\Enums\Seat;
use App\Models\Account;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\RecurringExpense;
use App\Models\Retainer;
use App\Models\Supplier;
use App\Support\ActivityCalendar;
use App\Support\CalendarEntry;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

pest()->group('integration');

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-09'));
    $this->account->forceFill(['created_at' => '2026-01-01', 'billing_exempt' => true])->save();
    app(FinanceSettings::class)->set(['activity_started_on' => '2024-01-01', 'activity_start_kind' => 'first']);
    actingAsAdmin();
});

/**
 * @param  list<CalendarEntryType>|null  $types
 * @return list<CalendarEntry>
 */
function calendarEntries(string $from, string $to, ?array $types = null): array
{
    return (new ActivityCalendar(new FinanceSettings, CarbonImmutable::today()))
        ->entries(CarbonImmutable::parse($from), CarbonImmutable::parse($to), $types ?? CalendarEntryType::cases());
}

/**
 * @param  list<CalendarEntry>  $entries
 * @return list<CalendarEntry>
 */
function ofType(array $entries, CalendarEntryType $type): array
{
    return array_values(array_filter($entries, fn (CalendarEntry $entry) => $entry->type === $type));
}

it('puts each kind on its own date', function () {
    $client = Client::factory()->create(['name' => 'Cliente X']);
    $paid = Receivable::factory()->for(Project::factory()->for($client), 'billable')->create(['amount' => 120000, 'due_on' => '2026-11-03']);
    Payment::factory()->for($paid)->create(['paid_on' => '2026-11-03', 'amount' => 120000]);
    Receivable::factory()->create(['description' => 'Fatura 23', 'amount' => 80000, 'due_on' => '2026-11-15']);
    Expense::factory()->unpaid()->for(Supplier::factory()->create(['name' => 'Coworking Lx']))
        ->create(['spent_on' => '2026-11-12', 'net_amount' => 15000, 'vat_rate' => 0]);

    $entries = calendarEntries('2026-11-01', '2026-11-30');
    [$payment] = ofType($entries, CalendarEntryType::Payment);
    [$receivable] = ofType($entries, CalendarEntryType::Receivable);
    [$expense] = ofType($entries, CalendarEntryType::Expense);

    expect($payment->date->toDateString())->toBe('2026-11-03')
        ->and($payment->title)->toBe('Recebido · Cliente X · '.Money::format(120000))
        ->and($receivable->date->toDateString())->toBe('2026-11-15')
        ->and($receivable->title)->toContain('A receber · ')->toContain('Fatura 23')->toEndWith(Money::format(80000))
        ->and($expense->date->toDateString())->toBe('2026-11-12')
        ->and($expense->title)->toBe('Coworking Lx · '.Money::format(15000).' · por pagar')
        ->and($expense->status)->toBe('unpaid')
        ->and($expense->url)->not->toBeNull();
});

it('shows the outstanding of a partly paid receivable and hides paid ones', function () {
    $partial = Receivable::factory()->create(['amount' => 80000, 'due_on' => '2026-11-20']);
    Payment::factory()->for($partial)->create(['paid_on' => '2026-11-05', 'amount' => 30000]);
    $paid = Receivable::factory()->create(['amount' => 50000, 'due_on' => '2026-11-21']);
    Payment::factory()->for($paid)->create(['paid_on' => '2026-11-06', 'amount' => 50000]);

    $entries = calendarEntries('2026-11-01', '2026-11-30');
    $receivables = ofType($entries, CalendarEntryType::Receivable);

    expect($receivables)->toHaveCount(1)
        ->and($receivables[0]->amount)->toBe(50000)
        ->and($receivables[0]->status)->toBe('partial')
        ->and(ofType($entries, CalendarEntryType::Payment))->toHaveCount(2);
});

it('leaves out receivables of cancelled jobs', function () {
    Receivable::factory()->for(Project::factory()->create(['status' => ProjectStatus::Cancelled]), 'billable')
        ->create(['due_on' => '2026-11-20']);

    expect(ofType(calendarEntries('2026-11-01', '2026-11-30'), CalendarEntryType::Receivable))->toBe([]);
});

it('projects retainers and recurring expenses only after what was generated', function () {
    $retainer = Retainer::factory()->for(Client::factory()->create(['name' => 'Cliente Z']))
        ->create(['due_day' => 25, 'starts_on' => '2026-11-01', 'net_amount' => 60000]);
    Receivable::factory()->for($retainer, 'billable')->create(['amount' => 60000, 'due_on' => '2026-11-25']);
    $template = RecurringExpense::factory()->create(['description' => 'Hetzner', 'day' => 18, 'starts_on' => '2026-11-01', 'net_amount' => 900]);
    Expense::factory()->for($template, 'recurringExpense')->create(['spent_on' => '2026-11-18', 'net_amount' => 900]);

    $entries = calendarEntries('2026-11-01', '2026-12-31');
    $retainers = ofType($entries, CalendarEntryType::ProjectedRetainer);
    $recurring = ofType($entries, CalendarEntryType::ProjectedRecurringExpense);

    expect(array_map(fn (CalendarEntry $entry) => $entry->date->toDateString(), $retainers))->toBe(['2026-12-25'])
        ->and($retainers[0]->title)->toBe('Avença · Cliente Z (prevista) · '.Money::format(60000))
        ->and(array_map(fn (CalendarEntry $entry) => $entry->date->toDateString(), $recurring))->toBe(['2026-12-18'])
        ->and($recurring[0]->title)->toBe('Hetzner (prevista) · '.Money::format(900))
        ->and(ofType($entries, CalendarEntryType::Receivable))->toHaveCount(1);
});

it('cuts projections to the range', function () {
    $retainer = Retainer::factory()->create(['due_day' => 25, 'starts_on' => '2026-11-01']);
    Receivable::factory()->for($retainer, 'billable')->create(['due_on' => '2026-11-25']);

    $dates = array_map(fn (CalendarEntry $entry) => $entry->date->toDateString(),
        ofType(calendarEntries('2027-01-01', '2027-01-31'), CalendarEntryType::ProjectedRetainer));

    expect($dates)->toBe(['2027-01-25']);
});

it('includes the obligations of the range with their status', function () {
    $obligations = ofType(calendarEntries('2026-11-01', '2026-11-30'), CalendarEntryType::Obligation);
    $ss = collect($obligations)->firstWhere('id', 'ss:2026-11');

    expect($ss->date->toDateString())->toBe('2026-11-20')
        ->and($ss->status)->toBe('due')
        ->and($ss->title)->toBe('Segurança Social · novembro · '.Money::format(2000))
        ->and($ss->url)->not->toBeNull()
        ->and($ss->detail)->toContain('Pagar entre 10 e 20 de novembro');
});

it('filters by type', function () {
    Expense::factory()->create(['spent_on' => '2026-11-12']);
    Payment::factory()->create(['paid_on' => '2026-11-12']);

    $entries = calendarEntries('2026-11-01', '2026-11-30', [CalendarEntryType::Expense]);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->type)->toBe(CalendarEntryType::Expense);
});

it('never shows another account\'s records', function () {
    $other = Account::factory()->create();
    CurrentAccount::run($other, function () {
        Expense::factory()->create(['spent_on' => '2026-11-12']);
        Payment::factory()->create(['paid_on' => '2026-11-12']);
        Retainer::factory()->create(['due_day' => 25, 'starts_on' => '2026-11-01']);
        RecurringExpense::factory()->create(['day' => 18, 'starts_on' => '2026-11-01']);
    });

    $entries = calendarEntries('2026-11-01', '2026-11-30');

    expect(collect($entries)->reject(fn (CalendarEntry $entry) => $entry->type === CalendarEntryType::Obligation)->all())->toBe([]);
});

it('works across the year boundary', function () {
    $retainer = Retainer::factory()->create(['due_day' => 25, 'starts_on' => '2026-11-01']);
    Receivable::factory()->for($retainer, 'billable')->create(['due_on' => '2026-11-25']);

    $entries = calendarEntries('2026-12-01', '2027-01-31');

    expect(array_map(fn (CalendarEntry $entry) => $entry->id, ofType($entries, CalendarEntryType::Obligation)))->toContain('ss:2026-12', 'ss:2027-01')
        ->and(array_map(fn (CalendarEntry $entry) => $entry->date->toDateString(), ofType($entries, CalendarEntryType::ProjectedRetainer)))->toBe(['2026-12-25', '2027-01-25']);
});

it('sorts the entries by date', function () {
    Expense::factory()->create(['spent_on' => '2026-11-25']);
    Expense::factory()->create(['spent_on' => '2026-11-02']);

    $dates = array_map(fn (CalendarEntry $entry) => $entry->date->toDateString(), calendarEntries('2026-11-01', '2026-11-30'));

    expect($dates)->toBe(collect($dates)->sort()->values()->all());
});

it('names the month or year each obligation is about', function () {
    $this->travelTo(Carbon::parse('2027-05-09'));
    Expense::factory()->for(Supplier::factory()->create(['seat' => Seat::EuropeanUnion]))
        ->create(['spent_on' => '2027-04-15', 'is_goods' => false, 'vat_rate' => 0, 'net_amount' => 1000]);

    $titles = collect(ofType(calendarEntries('2027-05-01', '2027-06-30'), CalendarEntryType::Obligation))->pluck('title', 'id');

    expect($titles['vat-reverse:2027-04'])->toStartWith('IVA das compras ao estrangeiro · abril · ')
        ->and($titles['irs-modelo3:2026'])->toBe('IRS (modelo 3) · 2026');
});
