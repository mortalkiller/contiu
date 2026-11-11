<?php

use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\Payment;
use App\Support\CompanyAccount;
use Illuminate\Support\Carbon;

pest()->group('integration', 'banking');

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-11-20')));

function line(string $date, int $amount, int $balance, string $status = 'pending', string $description = 'TRF'): BankTransaction
{
    static $n = 0;

    return BankTransaction::create(['hash' => 'h'.(++$n), 'booked_on' => $date, 'description' => $description, 'amount' => $amount, 'balance' => $balance, 'status' => $status, 'import_batch' => 'b']);
}

it('knows nothing without a statement', function () {
    $account = new CompanyAccount;

    expect($account->hasStatement())->toBeFalse()
        ->and($account->bankBalance())->toBeNull()
        ->and($account->difference())->toBeNull();
});

it('takes the opening balance from the first line and the bank balance from the lines', function () {
    line('2026-07-10', 150000, 160000, 'matched'); // there were already 100,00 € before the statement
    line('2026-10-06', -15100, 144900, 'matched');

    $account = new CompanyAccount;

    expect($account->startsOn()->toDateString())->toBe('2026-07-10')
        ->and($account->endsOn()->toDateString())->toBe('2026-10-06')
        ->and($account->openingBalance())->toBe(10000)
        ->and($account->bankBalance())->toBe(144900);
});

it('finds the opening balance whatever order same-day lines were imported in', function () {
    line('2026-07-10', -2000, 98000); // second movement of the day, imported first
    line('2026-07-10', 50000, 100000); // first movement: 50 000 before it

    expect((new CompanyAccount)->openingBalance())->toBe(50000);
});

it('says how much is still to transfer when a client paid into the personal account', function () {
    line('2026-11-01', 100000, 100000, 'matched'); // opening 0
    Payment::factory()->create(['amount' => 100000, 'paid_on' => '2026-11-01']); // that line
    Payment::factory()->create(['amount' => 73000, 'paid_on' => '2026-11-03']); // paid into the personal account
    Expense::factory()->create(['net_amount' => 8374, 'paid_on' => '2026-11-04', 'spent_on' => '2026-11-04']); // ChatGPT, paid personally
    line('2026-11-05', 0, 100000); // statement goes up to 05/11

    $account = new CompanyAccount;

    expect($account->contiuBalance())->toBe(164626)
        ->and($account->bankBalance())->toBe(100000)
        ->and($account->difference())->toBe(-64626);

    line('2026-11-06', 64626, 164626, 'own_transfer', 'TRF DE PEDRO MONTEIRO');

    expect((new CompanyAccount)->difference())->toBe(0);
});

it('leaves out what happened before the first statement and unpaid expenses', function () {
    Payment::factory()->create(['amount' => 50000, 'paid_on' => '2026-06-30']);
    Expense::factory()->unpaid()->create(['net_amount' => 5000, 'spent_on' => '2026-11-02']);
    line('2026-07-01', 0, 20000);

    expect((new CompanyAccount)->contiuBalance())->toBe(20000);
});

it('counts the lines still to treat up to the statement end', function () {
    line('2026-11-01', 1000, 1000);
    line('2026-11-02', 1000, 2000, 'matched');

    expect((new CompanyAccount)->pendingLines())->toBe(1);
});

it('works as of a given day for the month close', function () {
    line('2026-10-01', 10000, 10000, 'matched');
    Payment::factory()->create(['amount' => 10000, 'paid_on' => '2026-10-01']);
    line('2026-11-02', 5000, 15000);
    Payment::factory()->create(['amount' => 7000, 'paid_on' => '2026-11-02']);

    $october = new CompanyAccount(Carbon::parse('2026-10-31'));

    expect($october->endsOn()->toDateString())->toBe('2026-10-01')
        ->and($october->bankBalance())->toBe(10000)
        ->and($october->difference())->toBe(0)
        ->and((new CompanyAccount)->difference())->toBe(-2000);
});

it('projects the balance today from the statement and what was recorded since', function () {
    line('2026-11-01', 10000, 10000, 'matched');
    Payment::factory()->create(['amount' => 10000, 'paid_on' => '2026-11-01']);
    Payment::factory()->create(['amount' => 3000, 'paid_on' => '2026-11-15']); // after the statement

    expect((new CompanyAccount)->contiuBalanceAt(today()))->toBe(13000);
});

it('counts everything since the start when the statement begins with the account opening at zero', function () {
    Payment::factory()->create(['amount' => 50000, 'paid_on' => '2026-03-10']); // before the account existed
    Expense::factory()->create(['net_amount' => 10000, 'paid_on' => '2026-04-01', 'spent_on' => '2026-04-01']);
    line('2026-07-10', 30000, 30000); // account opened: balance before = 0

    $account = new CompanyAccount;

    expect($account->openingBalance())->toBe(0)
        ->and($account->contiuBalance())->toBe(40000)
        ->and($account->contiuBalanceAt(today()))->toBe(40000)
        ->and($account->difference())->toBe(-10000); // 100,00 € of the company still in the personal account
})->group('postgres');
