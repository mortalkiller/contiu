<?php

use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\Payment;
use App\Support\CompanyAccount;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

// These pass on SQLite too; they exist to be run against PostgreSQL (Supabase in production).

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-11-07')));

it('groups expenses by month with totals', function () {
    actingAsAdmin();
    Expense::factory()->create(['spent_on' => '2026-10-03', 'net_amount' => 1000]);
    Expense::factory()->create(['spent_on' => '2026-11-02', 'net_amount' => 2000]);

    Livewire::test(ListExpenses::class)->loadTable()
        ->set('tableGrouping', 'spent_on:desc')
        ->assertSee('Novembro 2026')
        ->assertSee('Outubro 2026');
});

it('lists unread database notifications', function () {
    $user = actingAsAdmin();
    Notification::make()->title('Mês por fechar')->sendToDatabase($user);

    expect($user->unreadNotifications()->where('data->format', 'filament')->count())->toBe(1);
});

it('compares the company account when the statement starts at the account opening', function () {
    // Opening at zero: everything received before the first line counts, with no lower date
    // bound. PostgreSQL has no year 0, so "since 0000-01-01" fails there.
    BankTransaction::create(['hash' => 'pg1', 'booked_on' => '2026-07-10', 'description' => 'TRF', 'amount' => 150000, 'balance' => 150000, 'import_batch' => 'x']);
    Payment::factory()->create(['amount' => 40000, 'paid_on' => '2026-06-01']);

    expect((new CompanyAccount)->contiuBalanceAt(today()))->toBe(40000);
});
