<?php

use App\Filament\MonthClose\Widgets\MonthExpensesTable;
use App\Filament\MonthClose\Widgets\MonthReceivablesTable;
use App\Filament\Pages\MonthClose;
use App\Models\Expense;
use App\Models\Receivable;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-03-20'));
    actingAsAdmin();
});

it('opens on the current month and says how much is left to deal with', function () {
    Receivable::factory()->create(['due_on' => '2026-03-05']);
    Expense::factory()->unpaid()->create(['spent_on' => '2026-03-02']);

    Livewire::test(MonthClose::class)
        ->assertSet('month', '2026-03')
        ->assertSee('Março 2026')
        ->assertSee('Faltam 2 itens');
});

it('moves to the previous and next month', function () {
    Livewire::test(MonthClose::class)
        ->callAction('previousMonth')
        ->assertSet('month', '2026-02')
        ->callAction('nextMonth')
        ->callAction('nextMonth')
        ->assertSet('month', '2026-04');
});

it('says the month is closed when nothing is left', function () {
    Livewire::test(MonthClose::class, ['month' => '2026-01'])
        ->assertSee('Mês fechado');
});

it('lists the month receivables and lets you record what came in', function () {
    $receivable = Receivable::factory()->create(['amount' => 20000, 'due_on' => '2026-03-05']);

    Livewire::test(MonthReceivablesTable::class, ['month' => '2026-03'])
        ->assertCanSeeTableRecords([$receivable])
        ->callTableAction('recordPayment', $receivable, data: [
            'paid_on' => '2026-03-20',
            'amount' => 200,
            'method' => 'transfer',
        ])
        ->assertHasNoTableActionErrors();

    expect($receivable->fresh()->outstanding)->toBe(0);
});

it('marks an expense as paid and stores its invoice number inline', function () {
    $expense = Expense::factory()->unpaid()->create(['spent_on' => '2026-03-02']);

    Livewire::test(MonthExpensesTable::class, ['month' => '2026-03'])
        ->assertCanSeeTableRecords([$expense])
        ->callTableAction('markPaid', $expense)
        ->call('updateTableColumnState', 'invoice_number', (string) $expense->getKey(), 'FT 2026/9');

    expect($expense->fresh())
        ->is_paid->toBeTrue()
        ->paid_on->toDateString()->toBe('2026-03-20')
        ->invoice_number->toBe('FT 2026/9');
});

it('updates the summary when moving from a closed month to one with items left', function () {
    Receivable::factory()->create(['amount' => 2000, 'due_on' => '2026-04-01']);

    Livewire::test(MonthClose::class, ['month' => '2026-03'])
        ->assertSee('Mês fechado')
        ->callAction('nextMonth')
        ->assertSet('month', '2026-04')
        ->assertSee('Falta 1 item')
        ->assertDontSee('Mês fechado')
        ->tap(fn ($page) => expect($page->instance()->getWidgetData())->toBe(['month' => '2026-04']));
});
