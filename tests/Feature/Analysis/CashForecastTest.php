<?php

use App\Actions\GenerateRecurringExpenses;
use App\Actions\GenerateRetainerPeriods;
use App\Enums\ProjectStatus;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\RecurringExpense;
use App\Models\Retainer;
use App\Support\CashForecast;
use App\Support\FinanceSettings;
use App\Support\ScheduleProjector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-06'));
    // Keep Segurança Social out of the way unless a test turns it on.
    app(FinanceSettings::class)->set(['activity_started_on' => '2026-10-01', 'ss_first_year_exempt' => true]);
});

function forecast(int $months = 3): CashForecast
{
    return new CashForecast(app(FinanceSettings::class), $months);
}

function row(CashForecast $forecast, int $index): array
{
    return $forecast->months()[$index];
}

it('covers the current month and the following ones', function () {
    expect(collect(forecast(3)->months())->pluck('label')->all())->toBe(['Novembro 2026', 'Dezembro 2026', 'Janeiro 2027'])
        ->and(forecast(6)->months())->toHaveCount(6);
});

it('puts open receivables in their month and rolls overdue ones into the current month', function () {
    $partial = Receivable::factory()->create(['amount' => 500, 'due_on' => '2026-12-15']);
    Payment::factory()->for($partial)->create(['amount' => 200]);
    Receivable::factory()->create(['amount' => 100, 'due_on' => '2026-09-01']);
    Receivable::factory()->create(['amount' => 999, 'due_on' => '2027-03-01']);
    $cancelled = Project::factory()->create(['status' => ProjectStatus::Cancelled]);
    Receivable::factory()->for($cancelled, 'billable')->create(['amount' => 777, 'due_on' => '2026-12-01']);

    $forecast = forecast();

    expect(row($forecast, 0))->toMatchArray(['inflows' => 100.0, 'overdueInflows' => 100.0])
        ->and(row($forecast, 1)['inflows'])->toBe(300.0)
        ->and(row($forecast, 2)['inflows'])->toBe(0.0);
});

it('projects retainer periods not generated yet without double counting existing ones', function () {
    $retainer = Retainer::factory()->create(['starts_on' => '2026-01-01', 'due_day' => 1, 'net_amount' => 20]);
    $retainer->receivables()->create(['description' => 'Nov 2026', 'amount' => 20, 'due_on' => '2026-11-01']);

    $forecast = forecast();

    expect(array_column($forecast->months(), 'inflows'))->toBe([20.0, 20.0, 20.0]);
});

it('stops projecting at the retainer end date and skips inactive retainers', function () {
    $ending = Retainer::factory()->create(['starts_on' => '2026-11-01', 'due_day' => 1, 'net_amount' => 20, 'ends_on' => '2026-12-31']);
    Retainer::factory()->create(['starts_on' => '2026-11-01', 'net_amount' => 50, 'is_active' => false]);

    expect(array_column(forecast()->months(), 'inflows'))->toBe([20.0, 20.0, 0.0]);
});

it('counts unpaid expenses and projects recurring ones after the latest generated expense', function () {
    Expense::factory()->unpaid()->create(['spent_on' => '2026-10-20', 'net_amount' => 50]);
    $template = RecurringExpense::factory()->create(['starts_on' => '2026-01-01', 'day' => 27, 'net_amount' => 10]);
    Expense::factory()->create(['recurring_expense_id' => $template->id, 'supplier_id' => $template->supplier_id, 'spent_on' => '2026-10-27', 'net_amount' => 10]);

    $forecast = forecast();

    expect(row($forecast, 0))->toMatchArray(['outflows' => 60.0, 'overdueOutflows' => 50.0])
        ->and(row($forecast, 1)['outflows'])->toBe(10.0)
        ->and(row($forecast, 2)['outflows'])->toBe(10.0);
});

it('adds the estimated Segurança Social contribution when it is due', function () {
    app(FinanceSettings::class)->set(['ss_first_year_exempt' => false]);
    $receivable = Receivable::factory()->create(['amount' => 3000, 'due_on' => '2026-08-01']);
    Payment::factory()->for($receivable)->create(['paid_on' => '2026-08-10', 'amount' => 3000]);

    $forecast = forecast();

    expect(array_column($forecast->months(), 'socialSecurity'))->toBe([149.8, 149.8, 0.0])
        ->and(row($forecast, 0)['outflows'])->toBe(149.8);
});

it('accumulates the balance from the current bank balance, or from zero when it is unknown', function () {
    Receivable::factory()->create(['amount' => 100, 'due_on' => '2026-12-10']);

    expect(forecast()->startsFromBankBalance())->toBeFalse()
        ->and(array_column(forecast()->months(), 'balance'))->toBe([0.0, 100.0, 100.0]);

    app(FinanceSettings::class)->set(['current_bank_balance' => 1000, 'current_bank_balance_on' => '2026-11-05']);

    expect(forecast()->startsFromBankBalance())->toBeTrue()
        ->and(array_column(forecast()->months(), 'balance'))->toBe([1000.0, 1100.0, 1100.0])
        ->and(forecast()->totals())->toMatchArray(['inflows' => 100.0, 'outflows' => 0.0, 'net' => 100.0]);
});

it('projects the same dates the generators would create', function () {
    $retainer = Retainer::factory()->create(['starts_on' => '2026-01-15', 'due_day' => 31]);
    $template = RecurringExpense::factory()->create(['starts_on' => '2026-02-01', 'day' => 30]);
    $until = CarbonImmutable::parse('2026-11-06');

    $projectedPeriods = collect(app(ScheduleProjector::class)->retainerDueDates($retainer, $until))->map->toDateString()->all();
    $projectedExpenses = collect(app(ScheduleProjector::class)->recurringExpenseDates($template, $until))->map->toDateString()->all();

    app(GenerateRetainerPeriods::class)->forRetainer($retainer, $until);
    app(GenerateRecurringExpenses::class)->forTemplate($template, $until);

    expect($projectedPeriods)->toBe($retainer->receivables()->pluck('due_on')->map->toDateString()->all())
        ->and($projectedExpenses)->toBe($template->expenses()->orderBy('spent_on')->pluck('spent_on')->map->toDateString()->all());
});
