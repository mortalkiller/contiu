<?php

use App\Actions\GenerateRecurringExpenses;
use App\Actions\SendFinanceReminders;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\RecurringExpense;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

pest()->group('integration');

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-03-10'));
    $this->user = User::factory()->create();
    $this->account->users()->attach($this->user, ['role' => 'owner']);
});

function notificationTitles(User $user): array
{
    return $user->fresh()->notifications->pluck('data.title')->all();
}

it('warns once when an open amount falls due within a week', function () {
    Receivable::factory()->create(['amount' => 10000, 'due_on' => '2026-03-15']);

    app(SendFinanceReminders::class)->run();
    app(SendFinanceReminders::class)->run();

    expect(notificationTitles($this->user))->toHaveCount(1)
        ->and(notificationTitles($this->user)[0])->toStartWith('Vence em 5 dias');
});

it('warns once when an open amount becomes overdue', function () {
    Receivable::factory()->create(['amount' => 10000, 'due_on' => '2026-03-09']);

    app(SendFinanceReminders::class)->run();
    app(SendFinanceReminders::class)->run();

    expect(notificationTitles($this->user))->toHaveCount(1)
        ->and(notificationTitles($this->user)[0])->toStartWith('Vencido');
});

it('does not warn about amounts already paid or due later', function () {
    $paid = Receivable::factory()->create(['amount' => 10000, 'due_on' => '2026-03-09']);
    Payment::factory()->for($paid)->create(['amount' => 10000]);
    Receivable::factory()->create(['due_on' => '2026-04-30']);

    app(SendFinanceReminders::class)->run();

    expect(notificationTitles($this->user))->toBe([]);
});

it('warns when a recurring expense is created as unpaid', function () {
    RecurringExpense::factory()->create(['starts_on' => '2026-03-01', 'day' => 1, 'description' => 'ChatGPT Pro']);

    app(GenerateRecurringExpenses::class)->forAll();

    expect(notificationTitles($this->user))->toBe(['Despesa por pagar: ChatGPT Pro']);
});

it('sends the reminders every day', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'finance:send-reminders'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 6 * * *');

    $this->artisan('finance:send-reminders')->assertSuccessful();
});

it('reminds on the 1st when the previous month still has items to close', function () {
    $this->travelTo(Carbon::parse('2026-04-01'));
    Expense::factory()->unpaid()->create(['spent_on' => '2026-03-15']);

    app(SendFinanceReminders::class)->run();

    expect(notificationTitles($this->user))->toContain('Fecho de Março 2026: falta 1 item');
});

it('does not send the month reminder on other days or when the month is closed', function () {
    $this->travelTo(Carbon::parse('2026-04-02'));
    $expense = Expense::factory()->unpaid()->create(['spent_on' => '2026-03-15']);
    app(SendFinanceReminders::class)->run(); // not the 1st

    $expense->update(['is_paid' => true, 'paid_on' => '2026-04-10']);
    $this->travelTo(Carbon::parse('2026-05-01'));
    app(SendFinanceReminders::class)->run(); // April has nothing left

    expect(collect(notificationTitles($this->user))->filter(fn ($title) => str_starts_with($title, 'Fecho de')))->toBeEmpty();
});
