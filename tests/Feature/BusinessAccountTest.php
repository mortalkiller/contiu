<?php

use App\Filament\Analysis\Widgets\CashForecastChart;
use App\Filament\Analysis\Widgets\CashForecastStats;
use App\Filament\Analysis\Widgets\CashForecastTable;
use App\Filament\Pages\BankStatementImport;
use App\Filament\Pages\CashForecast;
use App\Filament\Pages\FinanceSettingsPage;
use App\Filament\Pages\HelpCentre;
use App\Filament\Pages\MonthClose;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Payments\Pages\ManagePayments;
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

/*
 * "Tens uma conta bancária só para a atividade?" Without one there is no statement to import, so
 * everything built on it (reconciliation, the company account card, the forecast's balance) is
 * left out. Accounts from before the question keep it on.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-06'));
    actingAsAdmin();
});

function withoutBusinessAccount(): void
{
    app(FinanceSettings::class)->set(['has_business_account' => false]);
}

it('keeps the bank features for an account that never answered the question', function () {
    expect(app(FinanceSettings::class)->hasBusinessAccount())->toBeTrue();

    $this->get(BankStatementImport::getUrl())->assertOk();
    expect(CompanyAccountStats::canView())->toBeTrue();
});

it('has no statement page without a business account', function () {
    withoutBusinessAccount();

    $this->get(BankStatementImport::getUrl())->assertForbidden();
    expect(BankStatementImport::shouldRegisterNavigation())->toBeFalse()
        ->and(CompanyAccountStats::canView())->toBeFalse();
});

it('shows no reconciliation on payments and expenses without a business account', function () {
    withoutBusinessAccount();
    $payment = Payment::factory()->create(['paid_on' => '2026-11-02']);
    $expense = Expense::factory()->create(['is_paid' => true, 'paid_on' => '2026-11-02', 'spent_on' => '2026-11-02']);

    Livewire::test(ManagePayments::class)
        ->assertTableColumnHidden('reconciliation')
        ->assertTableActionHidden('reconciliation', $payment)
        ->assertDontSee('Por reconciliar');

    Livewire::test(ListExpenses::class)
        ->assertTableColumnHidden('reconciliation')
        ->assertTableActionHidden('reconciliation', $expense)
        ->assertDontSee('Por reconciliar');
});

it('still shows the reconciliation with a business account', function () {
    $payment = Payment::factory()->create(['paid_on' => '2026-11-02']);

    Livewire::test(ManagePayments::class)
        ->assertTableColumnVisible('reconciliation')
        ->assertTableActionVisible('reconciliation', $payment);
});

it('closes the month without the statement when there is no business account', function () {
    BankTransaction::create(['hash' => 'ba1', 'booked_on' => '2026-11-03', 'description' => 'COMPRA', 'amount' => -1000, 'balance' => 0, 'status' => 'pending', 'import_batch' => 'b']);

    expect((new MonthReview(Carbon::parse('2026-11-01')))->pendingCount())->toBe(1);

    withoutBusinessAccount();

    expect((new MonthReview(Carbon::parse('2026-11-01')))->pendingCount())->toBe(0);

    Livewire::test(MonthClose::class)
        ->assertActionHidden('bankStatement')
        ->assertSee('Mês fechado')
        ->assertDontSee('extrato');
});

it('forecasts what comes in and goes out, with no balance, without a business account', function () {
    withoutBusinessAccount();
    Receivable::factory()->create(['amount' => 40000, 'due_on' => '2026-12-10']);

    Livewire::test(CashForecast::class)
        ->assertDontSee('importa o extrato do banco')
        ->assertDontSee('O saldo acumulado começa em 0 €');

    Livewire::test(CashForecastTable::class, ['months' => 3])
        ->assertTableColumnHidden('balance');

    Livewire::test(CashForecastStats::class, ['months' => 3])
        ->assertSee('Diferença prevista')
        ->assertSee(Money::format(40000))
        ->assertDontSee('Saldo no fim')
        ->assertDontSee('Saldo mais baixo');

    $chart = Livewire::test(CashForecastChart::class, ['months' => 3]);
    $datasets = (fn () => $this->getData())->call($chart->instance())['datasets'];

    expect(array_column($datasets, 'label'))->toBe(['Entradas', 'Saídas'])
        ->and($chart->instance()->getHeading())->toBe('Entradas e saídas');
});

it('keeps the balance in the forecast with a business account', function () {
    Livewire::test(CashForecastTable::class, ['months' => 3])->assertTableColumnVisible('balance');
    Livewire::test(CashForecastStats::class, ['months' => 3])->assertSee('Saldo no fim');
});

it('turns the business account off and on in the settings, keeping the names', function () {
    app(FinanceSettings::class)->set(['account_names' => 'ANA SILVA']);

    Livewire::test(FinanceSettingsPage::class)
        ->assertSchemaStateSet(['has_business_account' => true, 'account_names' => 'ANA SILVA'], 'form')
        ->fillForm(['has_business_account' => false])
        ->assertFormFieldHidden('account_names')
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(FinanceSettings::class);

    expect($settings->hasBusinessAccount())->toBeFalse()
        ->and($settings->accountNames())->toBe(['ANA SILVA']);
});

it('explains the app without the statement when there is no business account', function () {
    withoutBusinessAccount();

    $this->get(HelpCentre::getUrl())
        ->assertOk()
        ->assertSee('Do trabalho ao recebimento')
        ->assertSee('Fecho do mês')
        ->assertDontSee('Transferência minha')
        ->assertDontSee(BankStatementImport::getUrl(), escape: false);
});
