<?php

use App\Actions\ApplyBankTransaction;
use App\Actions\ManageCreditApplication;
use App\Actions\RecordAllocatedPayment;
use App\Actions\RecordCustomerRefund;
use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Enums\PaymentMethod;
use App\Filament\Resources\FiscalDocuments\Pages\ListFiscalDocuments;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\CreditApplication;
use App\Models\CustomerRefund;
use App\Models\FiscalDocument;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\User;
use App\Support\ClientStatement;
use App\Support\CompanyAccount;
use App\Support\CurrentAccount;
use App\Support\DocumentedTurnover;
use App\Support\MonthReview;
use App\Support\Period;
use App\Support\PeriodSummary;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

function linkedCreditApplicationDocuments(array $note = [], array $invoice = [], int $amount = 80000): array
{
    $receivable = Receivable::factory()->create(['amount' => $amount]);
    $original = FiscalDocument::factory()->create($invoice + [
        'document_date' => '2026-11-01',
        'net_amount' => 80000,
        'vat_amount' => 0,
        'total_amount' => 80000,
    ]);
    $original->links()->create(['receivable_id' => $receivable->id]);
    $credit = FiscalDocument::factory()->create($note + [
        'type' => FiscalDocumentType::CreditNote,
        'document_date' => '2026-11-02',
        'net_amount' => -15000,
        'vat_amount' => 0,
        'total_amount' => -15000,
    ]);
    $credit->links()->create(['related_fiscal_document_id' => $original->id]);

    return [$receivable, $original, $credit];
}

function signInForCreditApplication(string $role = 'owner'): User
{
    $user = User::factory()->create();
    test()->account->users()->attach($user, ['role' => $role]);
    test()->actingAs($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant(test()->account);

    return $user;
}

beforeEach(fn () => $this->travelTo(now()->setDate(2026, 11, 10)->startOfDay()));

it('records and reconciles a partial customer refund without changing the original payment or billing', function () {
    signInForCreditApplication();
    [$charge, $invoice, $note] = linkedCreditApplicationDocuments();
    $payment = Payment::factory()->for($charge)->create(['amount' => 80000, 'paid_on' => '2026-11-01']);
    $line = BankTransaction::create([
        'hash' => 'customer-refund-test', 'import_batch' => 'teste', 'booked_on' => '2026-11-05',
        'description' => 'Reembolso cliente', 'amount' => -7000, 'balance' => 73000,
    ]);
    $billing = DocumentedTurnover::forYear(2026);

    Livewire::test(ListFiscalDocuments::class)
        ->callAction(TestAction::make('recordCustomerRefund')->table($note), [
            'paid_on' => '2026-11-05', 'amount' => '70.00', 'reference' => 'REF-1', 'bank_transaction_id' => $line->id,
        ])->assertHasNoActionErrors();

    $refund = CustomerRefund::query()->sole();
    $statement = new ClientStatement($charge->client);
    expect($refund->amount)->toBe(7000)
        ->and($refund->invoice_id)->toBe($invoice->id)
        ->and($line->fresh()->difference())->toBe(0)
        ->and($line->fresh()->status->value)->toBe('matched')
        ->and($payment->fresh()->amount)->toBe(80000)
        ->and($charge->fresh()->outstanding)->toBe(0)
        ->and((new CompanyAccount)->difference())->toBe(0)
        ->and((new PeriodSummary(Period::of(2026, 11)))->cashResult())->toBe(73000)
        ->and((new MonthReview(today()))->result())->toBe(73000)
        ->and($statement->entries()->where('kind', 'refund')->sole()['debit'])->toBe(7000)
        ->and($statement->entries()->where('kind', 'credit')->sole()['credit'])->toBe(7000)
        ->and($statement->balance())->toBe(0)
        ->and(DocumentedTurnover::forYear(2026))->toEqual($billing);

    app(ApplyBankTransaction::class)->undo($line);
    expect($refund->fresh()->bank_transaction_id)->toBeNull()
        ->and($line->fresh()->status->value)->toBe('pending');
    app(RecordCustomerRefund::class)->handle($note->id, 7000, '2026-11-05', 'REF-1', $line->id);
    expect(CustomerRefund::count())->toBe(1)->and($line->fresh()->difference())->toBe(0);
});

it('refuses refunds above received cash and prevents reusing a refunded credit to abate a charge', function () {
    signInForCreditApplication();
    [$charge, , $note] = linkedCreditApplicationDocuments();
    Payment::factory()->for($charge)->create(['amount' => 10000, 'paid_on' => '2026-11-03']);
    $refunds = app(RecordCustomerRefund::class);
    expect(fn () => $refunds->handle($note->id, 11000, '2026-11-04', 'REF-1'))->toThrow(ValidationException::class);
    $refunds->handle($note->id, 9000, '2026-11-04', 'REF-1');
    expect(fn () => $refunds->handle($note->id, 2000, '2026-11-04', 'REF-2'))->toThrow(ValidationException::class)
        ->and(fn () => app(ManageCreditApplication::class)->apply($note->id))->toThrow(ValidationException::class);
    expect(CustomerRefund::count())->toBe(1);
});

it('applies and reverses a credit from the document list without recording cash or changing the original invoice', function () {
    $owner = signInForCreditApplication();
    [$receivable, $invoice, $credit] = linkedCreditApplicationDocuments();
    $turnover = DocumentedTurnover::forYear(2026);

    Livewire::test(ListFiscalDocuments::class)
        ->callAction(TestAction::make('applyCredit')->table($credit))
        ->assertHasNoActionErrors();

    $application = CreditApplication::query()->sole();
    expect($receivable->fresh()->outstanding)->toBe(65000)
        ->and($receivable->fresh()->received)->toBe(0)
        ->and($receivable->fresh()->amount)->toBe(80000)
        ->and($invoice->fresh()->total_amount)->toBe(80000)
        ->and(DocumentedTurnover::forYear(2026))->toEqual($turnover);
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseHas('credit_applications', [
        'receivable_id' => $receivable->id,
        'invoice_id' => $invoice->id,
        'credit_note_id' => $credit->id,
        'amount' => 15000,
        'applied_by' => $owner->id,
    ]);

    $this->travel(1)->day();
    Livewire::test(ListFiscalDocuments::class)
        ->callAction(TestAction::make('reverseCredit')->table($credit), ['reason' => 'Aplicação registada por engano.'])
        ->assertHasNoActionErrors();

    expect($receivable->fresh()->outstanding)->toBe(80000)
        ->and($application->fresh()->reversed_on->toDateString())->toBe('2026-11-11')
        ->and($application->fresh()->reversed_by)->toBe($owner->id)
        ->and($application->fresh()->reversal_reason)->toBe('Aplicação registada por engano.');
});

it('reflects credits and allocations on their effective dates and reopens a fully credited balance on reversal', function () {
    signInForCreditApplication();
    [$receivable, , $credit] = linkedCreditApplicationDocuments();
    Payment::factory()->for($receivable)->create(['amount' => 65000, 'paid_on' => '2026-11-05']);
    $action = app(ManageCreditApplication::class);

    $application = $action->apply($credit->id);

    expect(Receivable::open()->whereKey($receivable)->exists())->toBeFalse()
        ->and($receivable->outstandingAt('2026-11-04'))->toBe(80000)
        ->and($receivable->outstandingAt('2026-11-09'))->toBe(15000)
        ->and($receivable->outstandingAt('2026-11-10'))->toBe(0);

    $this->travel(2)->days();
    $action->reverse($credit->id, $application->id, 'Correção de aplicação.');

    expect(Receivable::open()->whereKey($receivable)->exists())->toBeTrue()
        ->and($receivable->outstandingAt('2026-11-11'))->toBe(0)
        ->and($receivable->outstandingAt('2026-11-12'))->toBe(15000)
        ->and($receivable->fresh()->received)->toBe(65000);
});

it('rejects a duplicate application and any application above the open balance', function () {
    signInForCreditApplication();
    [$receivable, $invoice, $credit] = linkedCreditApplicationDocuments();
    $action = app(ManageCreditApplication::class);
    $application = $action->apply($credit->id);
    $other = FiscalDocument::factory()->create(['type' => FiscalDocumentType::CreditNote, 'total_amount' => 70000]);
    $other->links()->create(['related_fiscal_document_id' => $invoice->id]);

    expect(fn () => $action->apply($credit->id))->toThrow(ValidationException::class)
        ->and(fn () => $action->apply($other->id))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('credit_applications', 1);
    expect($receivable->outstanding)->toBe(65000);

    $action->reverse($credit->id, $application->id, 'Corrigir a data.');
    $new = $action->apply($credit->id);
    expect(fn () => $action->reverse($credit->id, $application->id, 'Pedido antigo.'))->toThrow(ValidationException::class)
        ->and($new->fresh()->reversed_on)->toBeNull();
});

it('requires an active issued EUR credit note and original invoice', function (array $note, array $invoice) {
    signInForCreditApplication();
    [, , $credit] = linkedCreditApplicationDocuments($note, $invoice);

    expect(fn () => app(ManageCreditApplication::class)->apply($credit->id))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('credit_applications', 0);
})->with([
    'voided note' => [['status' => FiscalDocumentStatus::Voided], []],
    'foreign currency note' => [['currency' => 'USD'], []],
    'received note' => [['direction' => FiscalDocumentDirection::Received], []],
    'other type' => [['type' => FiscalDocumentType::Receipt], []],
    'voided original' => [[], ['status' => FiscalDocumentStatus::Voided]],
    'foreign currency original' => [[], ['currency' => 'USD']],
    'future-dated note' => [['document_date' => '2026-11-11'], []],
]);

it('requires the explicit original invoice and its receivable link', function () {
    signInForCreditApplication();
    [, $invoice, $credit] = linkedCreditApplicationDocuments();
    $action = app(ManageCreditApplication::class);
    $credit->links()->delete();
    expect(fn () => $action->apply($credit->id))->toThrow(ValidationException::class);

    $credit->links()->create(['related_fiscal_document_id' => $invoice->id]);
    $invoice->links()->delete();
    expect(fn () => $action->apply($credit->id))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('credit_applications', 0);
});

it('refuses applications across accounts and from review-only users', function () {
    [, , $credit] = linkedCreditApplicationDocuments();
    [, , $other] = CurrentAccount::run(Account::factory()->create(), fn () => linkedCreditApplicationDocuments());
    signInForCreditApplication('accountant');

    expect(fn () => app(ManageCreditApplication::class)->apply($other->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(ManageCreditApplication::class)->apply($credit->id))->toThrow(HttpException::class);

    $this->assertDatabaseCount('credit_applications', 0);
});

it('makes the existing split payment writer respect the reduced balance', function () {
    signInForCreditApplication();
    [$receivable, , $credit] = linkedCreditApplicationDocuments();
    $other = Receivable::factory()->for($receivable->billable, 'billable')->create(['amount' => 20000]);
    app(ManageCreditApplication::class)->apply($credit->id);

    expect(fn () => app(RecordAllocatedPayment::class)->handle([
        'amount' => 80000,
        'paid_on' => today(),
        'method' => PaymentMethod::Transfer,
    ], [$receivable->id => 70000, $other->id => 10000]))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('payment_allocations', 0);
});

it('rejects a cross-account application at the database boundary', function () {
    [$receivable, $invoice, $credit] = linkedCreditApplicationDocuments();
    [$foreignReceivable] = CurrentAccount::run(Account::factory()->create(), fn () => linkedCreditApplicationDocuments());

    expect(fn () => CreditApplication::factory()->create([
        'receivable_id' => $foreignReceivable->id,
        'invoice_id' => $invoice->id,
        'credit_note_id' => $credit->id,
    ]))->toThrow(QueryException::class);
    $this->assertDatabaseCount('credit_applications', 0);
    expect($receivable->outstanding)->toBe(80000);
});

it('requires a new confirmation if the original invoice changes after the modal opens', function () {
    signInForCreditApplication();
    [, , $credit] = linkedCreditApplicationDocuments();
    [$otherReceivable, $otherInvoice] = linkedCreditApplicationDocuments();
    $page = Livewire::test(ListFiscalDocuments::class)
        ->mountAction(TestAction::make('applyCredit')->table($credit));
    $credit->links()->firstOrFail()->update(['related_fiscal_document_id' => $otherInvoice->id]);

    $page->call('callMountedAction');

    $this->assertDatabaseCount('credit_applications', 0);
    expect($otherReceivable->outstanding)->toBe(80000);
});
