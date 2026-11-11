<?php

use App\Actions\ManageCustomerAdvance;
use App\Actions\RecordAllocatedPayment;
use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentType;
use App\Enums\PaymentMethod;
use App\Exceptions\AccountIsReadOnly;
use App\Filament\Pages\WithholdingReviewPage;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Support\ClientStatement;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use App\Support\WithholdingReview;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

pest()->group('fiscal');

function withholdingReceipt(Payment $payment, array $overrides = []): FiscalDocument
{
    $document = FiscalDocument::factory()->create(array_replace([
        'type' => FiscalDocumentType::Receipt, 'document_date' => '2027-01-02',
        'net_amount' => 10005, 'vat_amount' => 2301, 'total_amount' => 12306,
    ], $overrides));
    $document->links()->create(['payment_id' => $payment->id]);

    return $document;
}

it('records source withholding for the payment year separately from the net payment and actual bank credit', function () {
    $payment = Payment::factory()->create(['amount' => 10055, 'paid_on' => '2026-12-31']);
    $receipt = withholdingReceipt($payment);
    $bank = BankTransaction::create(['hash' => 'withholding', 'booked_on' => '2026-12-31', 'description' => 'Cliente', 'amount' => 10055, 'import_batch' => 'test', 'status' => 'matched']);
    $payment->bankLink()->create(['bank_transaction_id' => $bank->id]);

    WithholdingReview::record($receipt->id, 2251);
    WithholdingReview::record($receipt->id, 2251);
    $annual = WithholdingReview::forYear(2026);

    expect($annual->retainedTotal)->toBe(2251)
        ->and($annual->rows->sole()['paymentAmount'])->toBe(10055)
        ->and($annual->rows->sole()['bankCredit'])->toBe(10055)
        ->and(WithholdingReview::forYear(2027)->retainedTotal)->toBeNull()
        ->and(CurrentAccount::run(Account::factory()->create(), fn () => WithholdingReview::forYear(2026)->rows->count()))->toBe(0);
    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseCount('bank_transactions', 1);
    $this->assertDatabaseCount('withholding_records', 1);
    expect($payment->refresh()->amount)->toBe(10055);
})->group('integration');

it('keeps the recorded payment separate when no individual bank credit is available', function () {
    $payment = Payment::factory()->create(['amount' => 10055, 'paid_on' => '2026-01-02']);
    $receipt = withholdingReceipt($payment);

    WithholdingReview::record($receipt->id, 2251);

    expect(WithholdingReview::forYear(2026)->rows->sole())->toMatchArray(['paymentAmount' => 10055, 'bankCredit' => null]);
})->group('integration');

it('does not assign the full bank credit to one of several linked receipts', function () {
    $payment = Payment::factory()->create(['amount' => 10055, 'paid_on' => '2026-01-02']);
    $other = Payment::factory()->create(['amount' => 1000]);
    $receipt = withholdingReceipt($payment);
    $bank = BankTransaction::create(['hash' => 'shared-credit', 'booked_on' => '2026-01-02', 'description' => 'Lote', 'amount' => 11055, 'import_batch' => 'test', 'status' => 'matched']);
    $payment->bankLink()->create(['bank_transaction_id' => $bank->id]);
    $other->bankLink()->create(['bank_transaction_id' => $bank->id]);

    WithholdingReview::record($receipt->id, 2251);

    expect(WithholdingReview::forYear(2026)->rows->sole())->toMatchArray(['paymentAmount' => 10055, 'bankCredit' => null]);
})->group('integration');

it('rejects ineligible or unreconciled source amounts before recording withholding', function (array $overrides, int $retained) {
    $payment = Payment::factory()->create(['amount' => 10055]);
    $document = withholdingReceipt($payment, $overrides);

    expect(fn () => WithholdingReview::record($document->id, $retained))->toThrow(ValidationException::class);
    $this->assertDatabaseEmpty('withholding_records');
})->with([
    'one cent off' => [[], 2250],
    'no withholding' => [[], 0],
    'retention charged against VAT' => [['net_amount' => 2000, 'vat_amount' => 10306], 2251],
    'invoice only' => [['type' => FiscalDocumentType::Invoice], 2251],
    'incoming source' => [['direction' => FiscalDocumentDirection::Received], 2251],
    'foreign currency' => [['currency' => 'USD'], 2251],
    'voided source' => [['status' => 'voided'], 2251],
])->group('integration');

it('prevents double counting one payment and flags evidence edited after confirmation', function () {
    $payment = Payment::factory()->create(['amount' => 10055, 'paid_on' => '2026-03-01']);
    $source = withholdingReceipt($payment);
    $duplicate = withholdingReceipt($payment);
    WithholdingReview::record($source->id, 2251);

    expect(fn () => WithholdingReview::record($duplicate->id, 2251))->toThrow(ValidationException::class);

    $source->update(['status' => 'voided']);
    $annual = WithholdingReview::forYear(2026);

    expect($annual->retainedTotal)->toBeNull()
        ->and($annual->rows->sole()['current'])->toBeFalse();
})->group('integration');

it('confirms the amount from the independent withholding page', function () {
    actingAsAdmin();
    $payment = Payment::factory()->create(['amount' => 10055, 'paid_on' => '2026-03-01']);
    $receipt = withholdingReceipt($payment);

    $this->get(WithholdingReviewPage::getUrl())->assertOk()->assertSee('Retenções na fonte');

    Livewire::test(WithholdingReviewPage::class, ['year' => 2026])
        ->set('documentId', (string) $receipt->id)
        ->set('retained', '22,51')
        ->call('record')
        ->assertHasNoErrors()
        ->assertSee($receipt->number);

    expect(WithholdingReview::forYear(2026)->retainedTotal)->toBe(2251);
})->group('ui');

it('rejects foreign tenant evidence and writes on a read-only account', function () {
    $foreign = CurrentAccount::run(Account::factory()->create(), fn () => withholdingReceipt(Payment::factory()->create(['amount' => 10055])));
    $local = withholdingReceipt(Payment::factory()->create(['amount' => 10055]));

    expect(fn () => WithholdingReview::record($foreign->id, 2251))->toThrow(ValidationException::class);

    $this->actingAs(ownerOf($this->account));
    $this->account->update(['trial_ends_at' => now()->subDay()]);

    expect(fn () => WithholdingReview::record($local->id, 2251))->toThrow(AccountIsReadOnly::class);
    $this->assertDatabaseEmpty('withholding_records');
})->group('integration');

it('keeps withholding review readable and refuses a direct write on a read-only account', function () {
    $this->actingAs(ownerOf($this->account));
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->account);
    $this->account->update(['trial_ends_at' => now()->subDay()]);

    Livewire::test(WithholdingReviewPage::class)
        ->assertSee('Retenções registadas')
        ->assertDontSee('Registar a partir de um documento')
        ->call('record')
        ->assertForbidden();
    $this->assertDatabaseEmpty('withholding_records');
})->group('ui');

it('attaches an issued receipt and reconciles exact retained cents separately from cash', function () {
    actingAsAdmin();
    $charge = Receivable::factory()->create(['amount' => 12306]);
    $invoice = FiscalDocument::factory()->create(['net_amount' => 10005, 'vat_amount' => 2301, 'total_amount' => 12306, 'document_date' => '2026-03-01']);
    $invoice->links()->create(['receivable_id' => $charge->id]);
    $payment = Payment::factory()->for($charge)->create(['amount' => 10055, 'paid_on' => '2026-03-02']);
    $allocation = $payment->allocations()->sole();
    $receipt = FiscalDocument::factory()->create([
        'type' => FiscalDocumentType::Receipt, 'net_amount' => 10005, 'vat_amount' => 2301, 'total_amount' => 12306,
    ]);

    expect(fn () => WithholdingReview::record($receipt->id, 2250, 2026, allocationId: $allocation->id))
        ->toThrow(ValidationException::class);
    expect($receipt->links()->exists())->toBeFalse();

    WithholdingReview::record($receipt->id, 2251, 2026, allocationId: $allocation->id);

    expect($receipt->links()->sole()->payment_id)->toBe($payment->id)
        ->and($receipt->links()->sole()->related_fiscal_document_id)->toBe($invoice->id)
        ->and($allocation->refresh()->amount)->toBe(10055)
        ->and($payment->refresh()->amount)->toBe(10055)
        ->and($charge->refresh()->amount)->toBe(12306)
        ->and($charge->outstanding)->toBe(0)
        ->and($charge->outstandingAt('2026-03-01'))->toBe(12306)
        ->and($charge->outstandingAt('2026-03-02'))->toBe(0)
        ->and(Receivable::query()->withOutstandingBalance()->findOrFail($charge->id)->projectedOutstanding())->toBe(0)
        ->and(Receivable::query()->open()->whereKey($charge->id)->exists())->toBeFalse()
        ->and((int) WithholdingReview::settlementsOn('2026-03-01')->sum('withholding_records.retained_amount'))->toBe(0)
        ->and((int) WithholdingReview::settlementsOn('2026-03-02')->sum('withholding_records.retained_amount'))->toBe(2251)
        ->and(WithholdingReview::forYear(2026)->retainedTotal)->toBe(2251);

    $receipt->update(['number' => 'RC alterado']);
    expect((int) WithholdingReview::settlementsOn('2026-03-02')->sum('withholding_records.retained_amount'))->toBe(0)
        ->and(WithholdingReview::forYear(2026)->retainedTotal)->toBeNull()
        ->and($charge->outstanding)->toBe(2251)
        ->and(Receivable::query()->open()->whereKey($charge->id)->exists())->toBeTrue();
})->group('integration');

it('records documented withholding without a second deduction when the charge was already net', function () {
    actingAsAdmin();
    $charge = Receivable::factory()->create(['amount' => 10055]);
    $invoice = FiscalDocument::factory()->create(['net_amount' => 10005, 'vat_amount' => 2301, 'total_amount' => 10055, 'document_date' => '2026-03-01']);
    $invoice->links()->create(['receivable_id' => $charge->id]);
    $payment = Payment::factory()->for($charge)->create(['amount' => 10055, 'paid_on' => '2026-03-02']);
    $receipt = withholdingReceipt($payment);
    WithholdingReview::record($receipt->id, 2251, 2026, allocationId: $payment->allocations()->sole()->id);

    expect(WithholdingReview::forYear(2026)->retainedTotal)->toBe(2251)
        ->and(WithholdingReview::settlementsOn('2026-03-02')->count())->toBe(0)
        ->and($payment->allocations()->sole()->amount)->toBe(10055);
})->group('integration');

it('records each withheld receipt against its own cash allocation in a split transfer', function () {
    actingAsAdmin();
    $client = Client::factory()->create();
    $project = Project::factory()->for($client)->create();
    $charges = Receivable::factory()->count(2)->for($project, 'billable')->create(['amount' => 12306]);
    foreach ($charges as $charge) {
        $invoice = FiscalDocument::factory()->create(['net_amount' => 10005, 'vat_amount' => 2301, 'total_amount' => 12306, 'document_date' => '2026-03-01']);
        $invoice->links()->create(['receivable_id' => $charge->id]);
    }
    $payment = app(RecordAllocatedPayment::class)->handle([
        'amount' => 20110, 'paid_on' => '2026-03-02', 'method' => PaymentMethod::Transfer,
    ], [$charges[0]->id => 10055, $charges[1]->id => 10055]);
    foreach ($payment->allocations as $allocation) {
        $receipt = withholdingReceipt($payment);
        WithholdingReview::record($receipt->id, 2251, 2026, allocationId: $allocation->id);
    }

    expect(WithholdingReview::forYear(2026)->retainedTotal)->toBe(4502)
        ->and(WithholdingReview::forYear(2026)->rows->pluck('cashAmount')->all())->toBe([10055, 10055])
        ->and($payment->refresh()->amount)->toBe(20110)
        ->and($payment->allocations()->pluck('amount')->all())->toBe([10055, 10055]);

    $statement = new ClientStatement($client);
    expect($statement->received())->toBe(20110)
        ->and($statement->entries()->where('kind', 'withholding')->sum('credit'))->toBe(4502)
        ->and($statement->balance())->toBe(0);
})->group('integration');

it('reports documented signal withholding before allocating it and settles it once on the allocation date', function () {
    actingAsAdmin();
    $this->travelTo(now()->setDate(2026, 11, 10));
    $client = Client::factory()->create(['vat_number' => 'PT508025338']);
    $charge = Receivable::factory()->for(Project::factory()->for($client), 'billable')->create(['amount' => 60000]);
    $advances = app(ManageCustomerAdvance::class);
    $signal = $advances->record($client->id, 15000, '2026-11-01', PaymentMethod::Transfer);
    $source = withholdingReceipt($signal, [
        'type' => FiscalDocumentType::InvoiceReceipt, 'document_date' => '2026-11-01',
        'net_amount' => 20000, 'vat_amount' => 0, 'total_amount' => 20000, 'party_tax_id' => '508025338',
    ]);

    expect(WithholdingReview::advanceRetention($signal, $source))->toBe(0);
    WithholdingReview::record($source->id, 5000, 2026);
    WithholdingReview::record($source->id, 5000, 2026);

    expect(WithholdingReview::advanceRetention($signal, $source))->toBe(5000)
        ->and(WithholdingReview::forYear(2026)->retainedTotal)->toBe(5000)
        ->and($charge->outstandingAt('2026-11-04'))->toBe(60000)
        ->and((new ClientStatement($client))->entries()->where('kind', 'withholding'))->toBeEmpty();

    $advances->apply($signal->id, $charge->id, '2026-11-05');
    expect($charge->outstandingAt('2026-11-04'))->toBe(60000)
        ->and($charge->outstandingAt('2026-11-05'))->toBe(40000)
        ->and($signal->allocations()->sole()->amount)->toBe(15000);

    $invoice = FiscalDocument::factory()->create([
        'document_date' => '2026-11-05', 'party_tax_id' => '508025338',
        'net_amount' => 40000, 'vat_amount' => 0, 'total_amount' => 40000,
    ]);
    $invoice->links()->create(['receivable_id' => $charge->id, 'related_fiscal_document_id' => $source->id]);
    $partial = Payment::factory()->for($charge)->create(['amount' => 10000, 'paid_on' => '2026-11-06']);
    $ordinary = withholdingReceipt($partial, ['document_date' => '2026-11-06', 'net_amount' => 10000, 'vat_amount' => 0, 'total_amount' => 10000]);
    WithholdingReview::reconcileReceipt($ordinary->id, $partial->allocations()->sole()->id, 0);

    $payment = Payment::factory()->for($charge)->create(['amount' => 22500, 'paid_on' => '2026-11-09']);
    $receipt = withholdingReceipt($payment, ['document_date' => '2026-11-09', 'net_amount' => 30000, 'vat_amount' => 0, 'total_amount' => 30000]);
    WithholdingReview::record($receipt->id, 7500, 2026, $payment->allocations()->sole()->id);

    $statement = new ClientStatement($client);
    expect($statement->received())->toBe(47500)
        ->and($statement->entries()->where('kind', 'withholding')->pluck('credit')->values()->all())->toBe([5000, 7500])
        ->and($statement->balance())->toBe(0)
        ->and($charge->outstandingAt('2026-11-09'))->toBe(0)
        ->and(Receivable::query()->open()->whereKey($charge->id)->exists())->toBeFalse()
        ->and(WithholdingReview::forYear(2026)->retainedTotal)->toBe(12500);
    $this->assertDatabaseCount('withholding_records', 2);

    $source->update(['status' => 'voided']);
    expect(WithholdingReview::advanceRetention($signal, $source))->toBe(0)
        ->and($charge->outstandingAt('2026-11-09'))->toBe(12500)
        ->and(WithholdingReview::forYear(2026)->retainedTotal)->toBeNull();
})->group('integration');

it('reconciles an issued invoice-receipt against its exact local cash allocation', function () {
    actingAsAdmin();
    $charge = Receivable::factory()->create(['amount' => 12300]);
    $source = FiscalDocument::factory()->create([
        'type' => FiscalDocumentType::InvoiceReceipt, 'document_date' => '2026-03-01',
    ]);
    $source->links()->create(['receivable_id' => $charge->id]);
    $payment = Payment::factory()->for($charge)->create(['amount' => 10050, 'paid_on' => '2026-03-01']);
    $allocation = $payment->allocations()->sole();

    WithholdingReview::reconcileReceipt($source->id, $allocation->id, 2250);
    WithholdingReview::reconcileReceipt($source->id, $allocation->id, 2250);

    expect($source->fresh()->links->payment_id)->toBe($payment->id)
        ->and($source->fresh()->links->related_fiscal_document_id)->toBeNull()
        ->and($charge->outstandingAt('2026-03-01'))->toBe(0);
    $this->assertDatabaseCount('withholding_records', 1);
    $this->assertDatabaseCount('payments', 1);
})->group('integration');

it('uses a separate receipt linked to the invoice as the documentary source for a signal', function () {
    actingAsAdmin();
    $client = Client::factory()->create(['vat_number' => 'PT508025338']);
    $signal = app(ManageCustomerAdvance::class)->record($client->id, 10055, '2026-01-02', PaymentMethod::Transfer);
    $invoice = FiscalDocument::factory()->create([
        'document_date' => '2026-01-02', 'net_amount' => 10005, 'vat_amount' => 2301, 'total_amount' => 12306,
        'party_tax_id' => '508025338',
    ]);
    $invoice->links()->create(['payment_id' => $signal->id]);
    $receipt = withholdingReceipt($signal, ['document_date' => '2026-01-03']);
    $receipt->links()->update(['related_fiscal_document_id' => $invoice->id]);

    WithholdingReview::record($receipt->id, 2251);
    expect(WithholdingReview::confirmedAdvanceEvidence($signal, $invoice)->fiscal_document_id)->toBe($receipt->id)
        ->and(WithholdingReview::advanceRetention($signal, $receipt))->toBe(0);

    $receipt->links()->update(['related_fiscal_document_id' => null]);
    expect(WithholdingReview::advanceRetention($signal, $invoice))->toBe(0)
        ->and(WithholdingReview::forYear(2026)->retainedTotal)->toBeNull();
})->group('integration');

describe('Withholding summary', function () {
    beforeEach(function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-10'));
        actingAsAdmin();
        app(FinanceSettings::class)->set(['activity_started_on' => '2020-01-01', 'activity_start_kind' => 'first']);
    });

    it('sums up the withholding review with nothing recorded', function () {
        Livewire::test(WithholdingReviewPage::class, ['year' => 2026])
            ->assertSeeInOrder(['Total retido com fonte conferida', 'Sem retenções em 2026'])
            ->assertSeeInOrder(['Por conferir', '0', 'Todas as fontes conferem'])
            ->assertSeeInOrder(['Recibos sem retenção registada', '0']);
    })->group('ui');
});
