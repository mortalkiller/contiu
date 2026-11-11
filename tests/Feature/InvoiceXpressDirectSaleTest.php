<?php

use App\Actions\ApplyBankTransaction;
use App\Actions\MatchBankTransactions;
use App\Enums\BankTransactionStatus;
use App\Enums\PaymentMethod;
use App\Enums\ReceivableStatus;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Filament\Resources\Receivables\Pages\ListReceivables;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\DirectInvoiceSale;
use App\Models\InvoiceXpressIssueAttempt;
use App\Models\Payment;
use App\Models\Receivable;
use App\Support\CashForecast;
use App\Support\ClientStatement;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use App\Support\InvoiceXpressDirectSale;
use App\Support\Period;
use App\Support\PeriodSummary;
use App\Support\TaxEstimator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

pest()->group('invoicexpress');

function directSaleAttempt(Client $client): InvoiceXpressIssueAttempt
{
    $key = (string) Str::uuid();
    $attempt = new InvoiceXpressIssueAttempt;
    $attempt->forceFill([
        'intent_key' => "direct:{$key}",
        'provider_target' => str_repeat('a', 64),
        'proprietary_uid' => (string) Str::uuid(),
        'snapshot' => [
            'direct' => ['key' => $key, 'clientId' => $client->id, 'dueOn' => '2026-12-04'],
            'totals' => ['total' => 12345],
            'issueOn' => '2026-11-10',
        ],
        'state' => InvoiceXpressIssueAttempt::Draft,
        'remote_draft_id' => 100,
    ])->save();

    return $attempt;
}

function recordDirectSale(InvoiceXpressIssueAttempt $attempt): Receivable
{
    return DB::transaction(function () use ($attempt): Receivable {
        Account::query()->lockForUpdate()->findOrFail(CurrentAccount::id());
        $lockedAttempt = InvoiceXpressIssueAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

        return InvoiceXpressDirectSale::record($lockedAttempt);
    });
}

it('records one unpaid charge for a verified direct sale attempt and returns the same charge on retry', function () {
    $this->travelTo('2026-11-10');
    $client = Client::factory()->create();
    $attempt = directSaleAttempt($client);

    expect(InvoiceXpressDirectSale::findReceivable($attempt))->toBeNull();

    $charge = recordDirectSale($attempt);
    $retried = recordDirectSale($attempt);

    $sale = DirectInvoiceSale::sole();
    expect($retried->is($charge))->toBeTrue()
        ->and(InvoiceXpressDirectSale::findReceivable($attempt)->is($charge))->toBeTrue()
        ->and($charge->billable->is($sale))->toBeTrue()
        ->and($sale->client->is($client))->toBeTrue()
        ->and($sale->issued_on->toDateString())->toBe('2026-11-10')
        ->and($sale->key)->toBe($attempt->snapshot['direct']['key'])
        ->and($charge->due_on->toDateString())->toBe('2026-12-04')
        ->and($charge->outstanding)->toBe(12345)
        ->and($charge->status)->toBe(ReceivableStatus::Open);

    $this->assertDatabaseCount('receivables', 1);
    $this->assertDatabaseCount('payments', 0);
})->group('integration');

it('cannot read another account’s direct charge or bill a client from that account', function () {
    $otherAccount = Account::factory()->create();
    $client = Client::factory()->create();
    $attempt = directSaleAttempt($client);
    recordDirectSale($attempt);

    CurrentAccount::run($otherAccount, function () use ($attempt, $client): void {
        expect(InvoiceXpressDirectSale::findReceivable($attempt))->toBeNull();

        $invalidAttempt = directSaleAttempt($client);
        expect(fn () => recordDirectSale($invalidAttempt))->toThrow(ValidationException::class);

        expect(DirectInvoiceSale::count())->toBe(0)
            ->and(Receivable::count())->toBe(0);
    });
})->group('integration');

it('rolls the direct sale and charge back with the surrounding finalization', function () {
    $attempt = directSaleAttempt(Client::factory()->create());

    expect(fn () => DB::transaction(function () use ($attempt): void {
        recordDirectSale($attempt);

        throw new RuntimeException('Fiscal document link failed');
    }))->toThrow(RuntimeException::class, 'Fiscal document link failed');

    expect(InvoiceXpressDirectSale::findReceivable($attempt))->toBeNull();
    $this->assertDatabaseCount('direct_invoice_sales', 0);
    $this->assertDatabaseCount('receivables', 0);
})->group('integration');

it('shows an unpaid direct invoice in the client statement and forecasts the cash by due date', function () {
    $this->travelTo('2026-11-10');
    $client = Client::factory()->create();
    recordDirectSale(directSaleAttempt($client));

    $statement = new ClientStatement($client);
    $forecast = app(CashForecast::class);

    expect($statement->charged())->toBe(12345)
        ->and($statement->received())->toBe(0)
        ->and($statement->balance())->toBe(12345)
        ->and($statement->entries()->sole()['date']->toDateString())->toBe('2026-12-04')
        ->and($forecast->inflowsByClientId())->toBe([$client->id => 12345])
        ->and(array_column($forecast->months(), 'inflows'))->toBe([0, 12345, 0])
        ->and((new PeriodSummary(Period::of(2026, 11)))->contracted())->toBe(12345)
        ->and((new PeriodSummary(Period::of(2026, 12)))->contracted())->toBe(0);
})->group('integration');

it('reconciles a direct invoice and reopens it if the bank reconciliation is undone', function () {
    $this->travelTo('2026-11-10');
    $client = Client::factory()->create(['name' => 'Oficina Atlântica']);
    $charge = recordDirectSale(directSaleAttempt($client));
    $transaction = BankTransaction::create([
        'hash' => 'direct-sale-transfer',
        'booked_on' => '2026-11-10',
        'description' => 'TRF OFICINA ATLANTICA',
        'amount' => 12345,
        'import_batch' => 'test',
    ]);

    $candidate = app(MatchBankTransactions::class)->for($transaction)->best();
    expect($candidate->model->is($charge))->toBeTrue();

    $reconciliation = app(ApplyBankTransaction::class);
    $payment = $reconciliation->accept($transaction, $candidate);

    expect($payment)->toBeInstanceOf(Payment::class)
        ->and($payment->client->is($client))->toBeTrue()
        ->and($transaction->fresh()->status)->toBe(BankTransactionStatus::Matched)
        ->and($charge->fresh()->status)->toBe(ReceivableStatus::Paid)
        ->and((new ClientStatement($client))->balance())->toBe(0)
        ->and((new PeriodSummary(Period::of(2026, 11)))->receivedByCategory())->toBe(['Sem categoria' => 12345]);

    $reconciliation->undo($transaction->fresh());

    expect($charge->fresh()->outstanding)->toBe(12345)
        ->and($charge->billable->fresh())->toBeInstanceOf(DirectInvoiceSale::class);
})->group('integration');

it('finds a direct charge by client and records a partial payment from the receivables list', function () {
    $this->travelTo('2026-11-10');
    actingAsAdmin();
    $client = Client::factory()->create(['name' => 'Oficina Atlântica']);
    $charge = recordDirectSale(directSaleAttempt($client));

    Livewire::test(ListReceivables::class)->loadTable()
        ->filterTable('billable_type', DirectInvoiceSale::class)
        ->searchTable('Oficina')
        ->assertCanSeeTableRecords([$charge])
        ->callTableAction('recordPayment', $charge, data: [
            'paid_on' => '2026-11-10',
            'amount' => 50,
            'method' => PaymentMethod::Transfer->value,
        ])
        ->assertHasNoActionErrors();

    $payment = Payment::sole();
    expect($payment->amount)->toBe(5000)
        ->and($charge->fresh()->outstanding)->toBe(7345);

    Livewire::test(ManagePayments::class)->loadTable()
        ->searchTable('Oficina')
        ->assertCanSeeTableRecords([$payment]);
})->group('ui');

it('preserves the verified VAT rate for the cash estimate after a direct invoice is paid', function () {
    $this->travelTo('2026-11-10');
    $attempt = directSaleAttempt(Client::factory()->create());
    $attempt->forceFill(['snapshot' => [
        ...$attempt->snapshot,
        'totals' => ['total' => 12300],
        'tax' => ['basisPoints' => 2300],
    ]])->save();
    $charge = recordDirectSale($attempt);

    Payment::factory()->for($charge)->create([
        'amount' => 12300,
        'paid_on' => '2026-11-10',
        'tax_exempt' => false,
    ]);

    expect((new TaxEstimator(app(FinanceSettings::class), 2026))->vatCollected())->toBe(2300);
})->group('integration');
