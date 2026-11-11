<?php

use App\Filament\Pages\BankStatementImport;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\MonthClose;
use App\Filament\Widgets\CompanyAccountCard;
use App\Filament\Widgets\CompanyAccountStats;
use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Receivable;
use App\Support\FinanceSettings;
use App\Support\Money;
use App\Support\MonthReview;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

pest()->group('banking');

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
})->group('ui');

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
})->group('ui');

it('says when the balances match', function () {
    statementLine('2026-11-01', 100000, 100000);
    Payment::factory()->for(Receivable::factory()->create(['amount' => 100000]))->create(['amount' => 100000, 'paid_on' => '2026-11-01']);

    Livewire::test(CompanyAccountStats::class)->assertSee('Bate certo');
})->group('ui');

it('says what the company owes when an expense was paid personally', function () {
    statementLine('2026-11-01', 0, 50000);
    Expense::factory()->create(['net_amount' => 8374, 'paid_on' => '2026-11-01', 'spent_on' => '2026-11-01']);

    Livewire::test(CompanyAccountStats::class)
        ->assertSee('A empresa deve-te')
        ->assertSee(Money::format(8374));
})->group('ui');

it('appears as stat cards on the bank statement page and the month close, and as one section on the dashboard', function () {
    $this->get(BankStatementImport::getUrl())->assertSeeLivewire(CompanyAccountStats::class);
    $this->get(MonthClose::getUrl(['month' => '2026-11']))->assertSeeLivewire(CompanyAccountStats::class);
    $this->get(Dashboard::getUrl())
        ->assertSeeLivewire(CompanyAccountCard::class)
        ->assertDontSeeLivewire(CompanyAccountStats::class);
})->group('ui');

it('shows the two balances and the difference in the dashboard section, with where to sort it out', function () {
    statementLine('2026-11-01', 100000, 100000);

    Livewire::test(CompanyAccountCard::class)
        ->assertSeeInOrder(['Conta da empresa', 'Saldo do banco comparado com o que registaste na Contiu.'])
        ->assertSeeInOrder(['Saldo no banco', Money::format(100000), 'Saldo segundo a Contiu', 'Diferença', 'Extrato até 01/11/2026.', 'Ver o que falta reconciliar']);
})->group('ui');

it('asks for a statement in the dashboard section when there is none', function () {
    Livewire::test(CompanyAccountCard::class)
        ->assertSee('Importa o extrato do banco para comparar o saldo.')
        ->assertSee('Importar extrato')
        ->assertDontSee('Saldo no banco');
})->group('ui');

it('leaves the company account out of the dashboard of an account without one', function () {
    app(FinanceSettings::class)->set(['has_business_account' => false]);

    $this->get(Dashboard::getUrl())->assertDontSeeLivewire(CompanyAccountCard::class);
})->group('ui');

it('keeps the month open while its statement lines are not treated', function () {
    statementLine('2026-10-06', -15100, 100000, 'pending');

    $october = new MonthReview(Carbon::parse('2026-10-01'));

    expect($october->pendingBankLines())->toBe(1)
        ->and($october->pendingCount())->toBe(1)
        ->and($october->isClosed())->toBeFalse();
})->group('integration');

it('counts the statement lines already explained on Extrato bancário, leaving ignored ones out', function () {
    statementLine('2026-11-01', 100000, 100000);
    statementLine('2026-11-02', -5000, 95000, 'own_transfer');
    statementLine('2026-11-03', -2000, 93000, 'pending');
    statementLine('2026-11-04', -100, 92900, 'ignored');

    Livewire::test(CompanyAccountStats::class, ['withLineProgress' => true])
        ->assertSeeInOrder(['Linhas tratadas', '2 de 3'])
        ->assertSee('66% das linhas tratadas');
})->group('ui');

it('keeps the line count off the dashboard card', function () {
    statementLine('2026-11-01', 100000, 100000);

    Livewire::test(CompanyAccountStats::class)->assertDontSee('Linhas tratadas');
})->group('ui');

it('marks what kind of suggestion each pending statement line has', function () {
    statementLine('2026-11-03', -2000, 93000, 'pending');
    statementLine('2026-11-02', -5000, 95000, 'own_transfer');

    Livewire::test(BankStatementImport::class)->loadTable()
        ->assertSee('data-suggestion="none"', escape: false)
        ->assertSee('Sem sugestão')
        ->set('activeTab', 'done')
        ->assertDontSee('data-suggestion', escape: false);
})->group('ui');
