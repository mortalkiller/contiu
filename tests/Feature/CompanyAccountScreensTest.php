<?php

use App\Filament\Pages\BankStatementImport;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\MonthClose;
use App\Filament\Widgets\CompanyAccountStats;
use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Receivable;
use App\Support\Money;
use App\Support\MonthReview;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-20'));
    actingAsAdmin();
});

function statementLine(string $date, int $amount, int $balance, string $status = 'matched'): BankTransaction
{
    static $n = 0;

    return BankTransaction::create(['hash' => 's'.(++$n), 'booked_on' => $date, 'description' => 'TRF', 'amount' => $amount, 'balance' => $balance, 'status' => $status, 'import_batch' => 'b']);
}

it('asks for a statement when there is none', function () {
    Livewire::test(CompanyAccountStats::class)
        ->assertSee('Conta da empresa')
        ->assertSee('Importa o extrato do banco');
});

it('shows the bank balance, Contiu balance and how much is still to transfer', function () {
    statementLine('2026-11-01', 100000, 100000);
    Payment::factory()->for(Receivable::factory()->create(['amount' => 100000]))->create(['amount' => 100000, 'paid_on' => '2026-11-01']);
    Payment::factory()->for(Receivable::factory()->create(['amount' => 73000]))->create(['amount' => 73000, 'paid_on' => '2026-11-03']); // into the personal account
    statementLine('2026-11-05', 0, 100000, 'pending');

    Livewire::test(CompanyAccountStats::class)
        ->assertSee('Saldo no banco')
        ->assertSee(Money::format(100000))
        ->assertSee('Saldo segundo a Contiu')
        ->assertSee(Money::format(173000))
        ->assertSee('Falta transferir para a conta da empresa')
        ->assertSee(Money::format(73000))
        ->assertSee('Extrato até 05/11/2026')
        ->assertSee('1 linha do extrato por tratar');
});

it('says when the balances match', function () {
    statementLine('2026-11-01', 100000, 100000);
    Payment::factory()->for(Receivable::factory()->create(['amount' => 100000]))->create(['amount' => 100000, 'paid_on' => '2026-11-01']);

    Livewire::test(CompanyAccountStats::class)->assertSee('Bate certo');
});

it('says what the company owes when an expense was paid personally', function () {
    statementLine('2026-11-01', 0, 50000);
    Expense::factory()->create(['net_amount' => 8374, 'paid_on' => '2026-11-01', 'spent_on' => '2026-11-01']);

    Livewire::test(CompanyAccountStats::class)
        ->assertSee('A empresa deve-te')
        ->assertSee(Money::format(8374));
});

it('appears on the bank statement page, the dashboard and the month close', function () {
    $this->get(BankStatementImport::getUrl())->assertSeeLivewire(CompanyAccountStats::class);
    $this->get(Dashboard::getUrl())->assertSeeLivewire(CompanyAccountStats::class);
    $this->get(MonthClose::getUrl(['month' => '2026-11']))->assertSeeLivewire(CompanyAccountStats::class);
});

it('keeps the month open while its statement lines are not treated', function () {
    statementLine('2026-10-06', -15100, 100000, 'pending');

    $october = new MonthReview(Carbon::parse('2026-10-01'));

    expect($october->pendingBankLines())->toBe(1)
        ->and($october->pendingCount())->toBe(1)
        ->and($october->isClosed())->toBeFalse();
});
