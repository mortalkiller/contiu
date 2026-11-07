<?php

use App\Actions\GenerateRecurringExpenses;
use App\Enums\ExpenseKind;
use App\Enums\PaymentMethod;
use App\Models\Expense;
use App\Models\RecurringExpense;
use App\Models\Retainer;
use Illuminate\Support\Carbon;

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-03-10')));

it('creates an unpaid expense on each occurrence up to today, copying the template', function () {
    $retainer = Retainer::factory()->create();
    $template = RecurringExpense::factory()->create([
        'starts_on' => '2026-01-01',
        'day' => 27,
        'description' => 'Servidor',
        'net_amount' => 2233,
        'vat_rate' => 0.23,
        'kind' => ExpenseKind::Direct,
        'retainer_id' => $retainer->id,
        'method' => PaymentMethod::Card,
    ]);

    $created = app(GenerateRecurringExpenses::class)->forTemplate($template);

    $expenses = Expense::query()->orderBy('spent_on')->get();

    expect($created)->toBe(2)
        ->and($expenses->pluck('spent_on')->map->toDateString()->all())->toBe(['2026-01-27', '2026-02-27'])
        ->and($expenses->first())
        ->is_paid->toBeFalse()
        ->paid_on->toBeNull()
        ->description->toBe('Servidor')
        ->kind->toBe(ExpenseKind::Direct)
        ->retainer_id->toBe($retainer->id)
        ->supplier_id->toBe($template->supplier_id)
        ->method->toBe(PaymentMethod::Card)
        ->recurring_expense_id->toBe($template->id)
        ->and($expenses->first()->total)->toBe(2747);
});

it('continues after the latest generated expense without filling older gaps', function () {
    $template = RecurringExpense::factory()->create(['starts_on' => '2025-10-01', 'day' => 1]);
    Expense::factory()->create(['recurring_expense_id' => $template->id, 'supplier_id' => $template->supplier_id, 'spent_on' => '2026-01-03']);

    app(GenerateRecurringExpenses::class)->forTemplate($template);

    expect($template->expenses()->orderBy('spent_on')->pluck('spent_on')->map->toDateString()->all())
        ->toBe(['2026-01-03', '2026-02-01', '2026-03-01']);
});

it('does nothing when run again on the same day', function () {
    $template = RecurringExpense::factory()->create(['starts_on' => '2026-01-01', 'day' => 1]);
    $generator = app(GenerateRecurringExpenses::class);

    $generator->forTemplate($template);

    expect($generator->forTemplate($template))->toBe(0)
        ->and($template->expenses()->count())->toBe(3);
});

it('respects the end date and inactive templates', function () {
    $ended = RecurringExpense::factory()->create(['starts_on' => '2026-01-01', 'day' => 1, 'ends_on' => '2026-01-31']);
    $inactive = RecurringExpense::factory()->create(['starts_on' => '2026-01-01', 'is_active' => false]);

    expect(app(GenerateRecurringExpenses::class)->forAll())->toBe(1)
        ->and($ended->expenses()->count())->toBe(1)
        ->and($inactive->expenses()->count())->toBe(0);
});
