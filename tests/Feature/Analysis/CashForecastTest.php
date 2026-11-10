<?php

use App\Actions\ApplyBankTransaction;
use App\Actions\GenerateRecurringExpenses;
use App\Actions\GenerateRetainerPeriods;
use App\Actions\RecordTaxPayment;
use App\Enums\BankTransactionStatus;
use App\Enums\CategoryType;
use App\Enums\ProjectStatus;
use App\Filament\Pages\CashForecast as CashForecastPage;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\RecurringExpense;
use App\Models\Retainer;
use App\Models\TaxObligationCompletion;
use App\Support\CashForecast;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use App\Support\FiscalReserve;
use App\Support\Money;
use App\Support\ScheduleProjector;
use App\Support\SocialSecurity;
use App\Support\VatPeriodReview;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-06'));
    // Keep Segurança Social out of the way unless a test turns it on: a first start in October 2026
    // only owes contributions from October 2027 (1.º enquadramento).
    app(FinanceSettings::class)->set(['activity_started_on' => '2026-10-01', 'activity_start_kind' => 'first']);
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
    $partial = Receivable::factory()->create(['amount' => 50000, 'due_on' => '2026-12-15']);
    Payment::factory()->for($partial)->create(['amount' => 20000]);
    Receivable::factory()->create(['amount' => 10000, 'due_on' => '2026-09-01']);
    Receivable::factory()->create(['amount' => 99900, 'due_on' => '2027-03-01']);
    $cancelled = Project::factory()->create(['status' => ProjectStatus::Cancelled]);
    Receivable::factory()->for($cancelled, 'billable')->create(['amount' => 77700, 'due_on' => '2026-12-01']);

    $forecast = forecast();

    expect(row($forecast, 0))->toMatchArray(['inflows' => 10000, 'overdueInflows' => 10000])
        ->and(row($forecast, 1)['inflows'])->toBe(30000)
        ->and(row($forecast, 2)['inflows'])->toBe(0);
});

it('projects retainer periods not generated yet without double counting existing ones', function () {
    $retainer = Retainer::factory()->create(['starts_on' => '2026-01-01', 'due_day' => 1, 'net_amount' => 2000]);
    $retainer->receivables()->create(['description' => 'Nov 2026', 'amount' => 2000, 'due_on' => '2026-11-01']);

    $forecast = forecast();

    expect(array_column($forecast->months(), 'inflows'))->toBe([2000, 2000, 2000]);
});

it('stops projecting at the retainer end date and skips inactive retainers', function () {
    $ending = Retainer::factory()->create(['starts_on' => '2026-11-01', 'due_day' => 1, 'net_amount' => 2000, 'ends_on' => '2026-12-31']);
    Retainer::factory()->create(['starts_on' => '2026-11-01', 'net_amount' => 5000, 'is_active' => false]);

    expect(array_column(forecast()->months(), 'inflows'))->toBe([2000, 2000, 0]);
});

it('counts unpaid expenses and projects recurring ones after the latest generated expense', function () {
    Expense::factory()->unpaid()->create(['spent_on' => '2026-10-20', 'net_amount' => 5000]);
    $template = RecurringExpense::factory()->create(['starts_on' => '2026-01-01', 'day' => 27, 'net_amount' => 1000]);
    Expense::factory()->create(['recurring_expense_id' => $template->id, 'supplier_id' => $template->supplier_id, 'spent_on' => '2026-10-27', 'net_amount' => 1000]);

    $forecast = forecast();

    expect(row($forecast, 0))->toMatchArray(['outflows' => 6000, 'overdueOutflows' => 5000])
        ->and(row($forecast, 1)['outflows'])->toBe(1000)
        ->and(row($forecast, 2)['outflows'])->toBe(1000);
});

it('adds the estimated Segurança Social contribution when it is due', function () {
    app(FinanceSettings::class)->set(['activity_started_on' => '2024-01-01']);
    $receivable = Receivable::factory()->create(['amount' => 300000, 'due_on' => '2026-08-01']);
    Payment::factory()->for($receivable)->create(['paid_on' => '2026-08-10', 'amount' => 300000]);

    $forecast = forecast();

    // Each month pays the contribution of the month before: November to January pay October to
    // December, all set by the income of jul–set.
    expect(array_column($forecast->months(), 'socialSecurity'))->toBe([14980, 14980, 14980])
        ->and(row($forecast, 0)['outflows'])->toBe(14980);
});

it('starts from the imported statement plus what was recorded since, or from zero without one', function () {
    Receivable::factory()->create(['amount' => 10000, 'due_on' => '2026-12-10']);

    expect(forecast()->startsFromBankBalance())->toBeFalse()
        ->and(array_column(forecast()->months(), 'balance'))->toBe([0, 10000, 10000]);

    BankTransaction::create(['hash' => 's1', 'booked_on' => '2026-11-02', 'description' => 'TRF', 'amount' => 20000, 'balance' => 100000, 'import_batch' => 'b']);
    // Fully paid receivables, so nothing of them is still expected in the forecast.
    Payment::factory()->for(Receivable::factory()->create(['amount' => 20000]))->create(['amount' => 20000, 'paid_on' => '2026-11-02']); // that line
    Payment::factory()->for(Receivable::factory()->create(['amount' => 5000]))->create(['amount' => 5000, 'paid_on' => '2026-11-05']); // after the statement

    expect(forecast()->startsFromBankBalance())->toBeTrue()
        ->and(forecast()->startingBalance())->toBe(105000)
        ->and(array_column(forecast()->months(), 'balance'))->toBe([105000, 115000, 115000])
        ->and(forecast()->totals())->toMatchArray(['inflows' => 10000, 'outflows' => 0, 'net' => 10000]);
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

it('keeps reserved money separate from the bank balance and tax payments already recorded', function () {
    actingAsAdmin();
    $taxCategory = Category::factory()->create([
        'type' => CategoryType::Expense,
        'name' => RecordTaxPayment::CATEGORY,
    ]);
    Expense::factory()->for($taxCategory)->create(['net_amount' => 9001, 'paid_on' => '2026-11-05']);
    Expense::factory()->for($taxCategory)->unpaid()->create(['net_amount' => 1100]);
    BankTransaction::create([
        'hash' => 'reserve-test', 'booked_on' => '2026-11-06', 'description' => 'Saldo',
        'amount' => 10000, 'balance' => 110000, 'import_batch' => 'b',
    ]);

    Livewire::test(CashForecastPage::class)
        ->callAction('setTaxReserve', data: ['amount' => '200,03'])
        ->assertHasNoActionErrors()
        ->assertSee('Reserva fiscal');

    expect(forecast()->reserve())->toBe(['reserved' => 20003, 'available' => 79997, 'paidThisYear' => 9001])
        ->and(forecast()->startingBalance())->toBe(100000);
});

it('keeps the available balance unknown without a statement and rejects invalid reserves', function () {
    actingAsAdmin();
    Livewire::test(CashForecastPage::class)
        ->callAction('setTaxReserve', data: ['amount' => '-20,03'])
        ->assertHasActionErrors(['amount']);
    Livewire::test(CashForecastPage::class)
        ->callAction('setTaxReserve', data: ['amount' => '200,03'])
        ->assertHasNoActionErrors();

    expect(forecast()->reserve())->toBe(['reserved' => 20003, 'available' => null, 'paidThisYear' => 0])
        ->and(CurrentAccount::run(Account::factory()->create(), fn () => (new CashForecast(new FinanceSettings))->reserve()['reserved']))->toBe(0);
});

it('uses confirmed fiscal amounts and omits an already paid contribution and overlapping VAT reviews', function () {
    actingAsAdmin();
    app(FinanceSettings::class)->set(['activity_started_on' => '2024-01-01']);
    $security = app(SocialSecurity::class);
    $security->confirmContribution('2026-Q3', '2026-10', 12345);
    $security->confirmContribution('2026-Q3', '2026-11', 14001);
    TaxObligationCompletion::query()->create(['key' => 'ss:2026-11', 'completed_at' => now()]);
    VatPeriodReview::forPeriod(2026, 8)->confirm(9501, 500, 0, 'Confirmado', '2026-11-30');
    VatPeriodReview::forPeriod(2026, 9)->confirm(9001, 500, 0, 'Pago', '2026-11-30', '2026-11-04');
    VatPeriodReview::forPeriod(2026, 10)->confirm(7000, 0, 0, 'Mensal', '2026-12-05');
    VatPeriodReview::forPeriod(2026, 10, 3)->confirm(21000, 0, 0, 'Trimestral', '2027-01-10');

    $forecast = forecast();

    expect(row($forecast, 0))->toMatchArray(['socialSecurity' => 0, 'confirmedVat' => 9001])
        ->and(row($forecast, 1))->toMatchArray(['socialSecurity' => 14001, 'socialSecurityConfirmed' => true, 'confirmedVat' => 0])
        ->and(row($forecast, 2)['confirmedVat'])->toBe(0);
});

it('shows the part of the fiscal reserve supported by an explicitly linked outgoing own transfer', function () {
    actingAsAdmin();
    $transfer = BankTransaction::create([
        'hash' => 'reserve-out', 'booked_on' => '2026-11-06', 'description' => 'Transferência para poupança',
        'amount' => -12001, 'balance' => 87999, 'import_batch' => 'b', 'status' => BankTransactionStatus::OwnTransfer,
    ]);

    Livewire::test(CashForecastPage::class)
        ->callAction('setTaxReserve', data: ['amount' => '200,03', 'transfer_id' => $transfer->id])
        ->assertHasNoActionErrors()
        ->assertSee('Parcialmente comprovada')
        ->assertSee(Money::format(12001))
        ->assertSee(Money::format(8002));

    expect(forecast()->reserve())->toBe(['reserved' => 20003, 'available' => 79997, 'paidThisYear' => 0])
        ->and(forecast()->totals())->toMatchArray(['inflows' => 0, 'outflows' => 0, 'endBalance' => 100000])
        ->and($transfer->fresh()->status)->toBe(BankTransactionStatus::OwnTransfer);
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('expenses', 0);

    Livewire::test(CashForecastPage::class)
        ->mountAction('setTaxReserve')
        ->assertSchemaStateSet(['transfer_id' => (string) $transfer->id])
        ->fillForm(['amount' => '80,02', 'transfer_id' => $transfer->id])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertSee('Comprovada pela transferência');

    Livewire::test(CashForecastPage::class)
        ->callAction('setTaxReserve', data: ['amount' => '80,02', 'transfer_id' => null])
        ->assertHasNoActionErrors()
        ->assertSee('Valor declarado manualmente');
});

it('flags a linked transfer that has been undone or removed and keeps the reserve as declared', function (string $change) {
    actingAsAdmin();
    $transfer = BankTransaction::create([
        'hash' => 'reserve-invalidated', 'booked_on' => '2026-11-06', 'description' => 'Transferência para poupança',
        'amount' => -15000, 'balance' => 85000, 'import_batch' => 'b', 'status' => BankTransactionStatus::OwnTransfer,
    ]);

    Livewire::test(CashForecastPage::class)
        ->callAction('setTaxReserve', data: ['amount' => '150', 'transfer_id' => $transfer->id])
        ->assertHasNoActionErrors()
        ->assertSee('Comprovada pela transferência');

    match ($change) {
        'undo' => app(ApplyBankTransaction::class)->undo($transfer),
        'delete' => $transfer->delete(),
        'amount' => $transfer->update(['amount' => -14000]),
    };

    Livewire::test(CashForecastPage::class)
        ->assertSee('Comprovativo inválido')
        ->assertDontSee('Comprovada pela transferência');
    expect(forecast()->reserve()['reserved'])->toBe(15000);
})->with(['undo', 'delete', 'amount']);

it('refuses an ineligible fiscal reserve transfer without changing the saved amount', function (string $kind) {
    actingAsAdmin();
    app(FinanceSettings::class)->set(['tax_reserved_amount' => 9000]);
    $account = $kind === 'other account' ? Account::factory()->create() : $this->account;
    $transfer = CurrentAccount::run($account, fn () => BankTransaction::create([
        'hash' => 'reserve-ineligible', 'booked_on' => $kind === 'future' ? '2026-11-07' : '2026-11-06',
        'description' => 'Transferência', 'amount' => $kind === 'incoming' ? 12000 : -12000,
        'import_batch' => 'b',
        'status' => $kind === 'pending' ? BankTransactionStatus::Pending : BankTransactionStatus::OwnTransfer,
    ]));

    Livewire::test(CashForecastPage::class)
        ->callAction('setTaxReserve', data: ['amount' => '100', 'transfer_id' => $transfer->id])
        ->assertHasActionErrors(['transfer_id']);

    expect(forecast()->reserve()['reserved'])->toBe(9000);
})->with(['other account', 'pending', 'incoming', 'future']);

it('requires a positive reserve to save a transfer as evidence', function () {
    actingAsAdmin();
    app(FinanceSettings::class)->set(['tax_reserved_amount' => 9000]);
    $transfer = BankTransaction::create([
        'hash' => 'reserve-zero', 'booked_on' => '2026-11-06', 'description' => 'Transferência',
        'amount' => -9000, 'import_batch' => 'b', 'status' => BankTransactionStatus::OwnTransfer,
    ]);

    $page = Livewire::test(CashForecastPage::class)
        ->callAction('setTaxReserve', data: ['amount' => '0', 'transfer_id' => $transfer->id])
        ->assertHasActionErrors(['amount']);

    expect($page->instance()->getErrorBag()->first('mountedActions.0.data.amount'))
        ->toBe('Indica um montante superior a zero para associar a transferência.');
    expect(forecast()->reserve()['reserved'])->toBe(9000);
});

it('keeps the fiscal reserve read-only after the account loses write access', function () {
    app(FinanceSettings::class)->set(['tax_reserved_amount' => 9000]);
    $this->actingAs(ownerOf($this->account));
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->account);
    $this->account->update(['trial_ends_at' => now()->subDay()]);

    Livewire::test(CashForecastPage::class)
        ->assertSee(Money::format(9000))
        ->assertActionHidden('setTaxReserve');

    expect(fn () => app(FiscalReserve::class)->save(15000, null))->toThrow(HttpException::class);
    expect(forecast()->reserve()['reserved'])->toBe(9000);
});
