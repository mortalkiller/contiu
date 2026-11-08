<?php

use App\Actions\GenerateRecurringExpenses;
use App\Actions\MatchBankTransactions;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Receivable;
use App\Models\RecurringExpense;
use App\Models\User;
use App\Support\CurrentAccount;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-03-10'));
    $this->mine = $this->account;
    $this->other = Account::factory()->create();
    $this->mineUser = ownerOf($this->mine);
    $this->otherUser = ownerOf($this->other);
});

function titlesOf(User $user): array
{
    return $user->fresh()->notifications->pluck('data.title')->all();
}

it('sends each account its own reminders', function () {
    Receivable::factory()->create(['amount' => 10000, 'due_on' => '2026-03-01']);
    CurrentAccount::run($this->other, fn () => Receivable::factory()->count(2)->create(['amount' => 10000, 'due_on' => '2026-03-02']));

    $this->artisan('finance:send-reminders')->expectsOutputToContain('Avisos enviados: 3')->assertSuccessful();

    expect(titlesOf($this->mineUser))->toHaveCount(1)
        ->and(titlesOf($this->otherUser))->toHaveCount(2);
});

it('links recurring-expense notifications to the right account', function () {
    CurrentAccount::run($this->other, fn () => RecurringExpense::factory()->create(['starts_on' => '2026-03-01', 'day' => 1]));

    $this->artisan('finance:generate-recurring')->expectsOutputToContain('Despesas criadas: 1')->assertSuccessful();

    $notifications = $this->otherUser->fresh()->notifications;
    expect($notifications)->toHaveCount(1)
        ->and($notifications->first()->data['actions'][0]['url'])->toContain('/'.$this->other->slug.'/')
        ->and($this->mineUser->fresh()->notifications)->toHaveCount(0);
});

it('does not notify super admins who are not members of the account', function () {
    $outsider = superAdminOf(Account::factory()->create());
    RecurringExpense::factory()->create(['starts_on' => '2026-03-01', 'day' => 1]);

    app(GenerateRecurringExpenses::class)->forAll();

    expect($outsider->fresh()->notifications)->toHaveCount(0)
        ->and($this->mineUser->fresh()->notifications)->toHaveCount(1);
});

it('recognises own transfers only by the current account users names', function () {
    $this->other->users()->attach(User::factory()->create(['name' => 'Maria Fernandes']), ['role' => 'owner']);
    $transaction = BankTransaction::create(['hash' => 'x1', 'booked_on' => '2026-03-02', 'description' => 'TRF MARIA FERNANDES', 'amount' => -4000, 'import_batch' => 'x']);

    expect(app(MatchBankTransactions::class)->for($transaction)->candidates->pluck('type'))->not->toContain('own_transfer');

    CurrentAccount::run($this->other, fn () => expect(app(MatchBankTransactions::class)->for($transaction)->candidates->pluck('type'))->toContain('own_transfer'));
});
