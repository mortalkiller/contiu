<?php

use App\Actions\ManageCustomerAdvance;
use App\Actions\RecordAllocatedPayment;
use App\Enums\FiscalDocumentType;
use App\Enums\PaymentMethod;
use App\Filament\Pages\InvoiceXpressReceiptPage;
use App\Filament\Pages\WithholdingReviewPage;
use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\InvoiceXpressConfig;
use App\Models\InvoiceXpressReceiptAttempt;
use App\Models\InvoiceXpressSnapshot;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\InvoiceXpressReceipt;
use App\Support\WithholdingReview;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

pest()->group('invoicexpress');

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(now()->setDate(2026, 11, 10));
});

function receiptProviderSetup(): void
{
    actingAsAdmin();
    InvoiceXpressConfig::store('receipt-test', 'fake-secret');
    config(['services.invoicexpress.catalog_safe_accounts' => ['receipt-test']]);
}

function receiptInvoice(Receivable $charge, int $id, string $type = 'Invoice'): array
{
    $document = FiscalDocument::factory()->create([
        'number' => 'A/'.$id,
        'document_date' => '2026-10-01',
        'origin' => 'InvoiceXpress',
        'net_amount' => $charge->amount,
        'vat_amount' => 0,
        'total_amount' => $charge->amount,
    ]);
    $document->links()->create(['receivable_id' => $charge->id]);
    InvoiceXpressSnapshot::query()->create([
        'subdomain' => 'receipt-test',
        'provider_id' => $id,
        'source_hash' => str_repeat('a', 64),
        'source' => ['provider_type' => $type],
        'fiscal_document_id' => $document->id,
    ]);

    return [$document, [
        'id' => $id, 'type' => $type, 'status' => 'final', 'archived' => false,
        'sequence_id' => $type === 'Invoice' ? 14 : 15,
        'date' => '01/10/2026', 'inverted_sequence_number' => 'A/'.$id,
        'currency' => 'Euro', 'retention' => null, 'total' => $charge->amount / 100,
        'before_taxes' => $charge->amount / 100, 'taxes' => 0,
        'client' => ['id' => 83, 'name' => 'Cliente'],
    ]];
}

function receiptAllocation(): array
{
    $charge = Receivable::factory()->create(['amount' => 10001]);
    [$original, $invoice] = receiptInvoice($charge, 101);
    $payment = Payment::factory()->for($charge)->create([
        'amount' => 9901, 'paid_on' => '2026-10-15', 'method' => PaymentMethod::Transfer,
    ]);

    return [$payment->allocations()->firstOrFail(), $original, $invoice];
}

function receiptSeries(): array
{
    return [
        'id' => 14, 'serie' => 'A', 'current_invoice_sequence_id' => 14,
        'current_simplified_invoice_sequence_id' => 15, 'current_receipt_sequence_id' => 16,
        'current_receipt_validation_code' => 'RCABC123',
    ];
}

function receiptResponse(int $id, string $note, float $amount): array
{
    return [
        'id' => $id, 'type' => 'Receipt', 'status' => 'final',
        'date' => '15/10/2026', 'inverted_sequence_number' => 'A/'.$id, 'observations' => $note,
        'sequence_id' => 16, 'atcud' => 'RCABC123-'.$id,
        'currency' => 'Euro', 'retention' => null, 'total' => $amount, 'before_taxes' => $amount, 'taxes' => 0,
        'client' => ['id' => 83, 'name' => 'Cliente'],
    ];
}

it('limits the contextual receipts page to the selected fiscal document', function () {
    receiptProviderSetup();
    [$allocation, $invoice] = receiptAllocation();
    [$other] = receiptAllocation();

    Livewire::withQueryParams(['invoice' => (string) $invoice->id])
        ->test(InvoiceXpressReceiptPage::class)
        ->assertCanSeeTableRecords([$allocation])
        ->assertCanNotSeeTableRecords([$other]);

    Http::assertNothingSent();
})->group('ui');

it('previews and records a separate receipt for each allocation of a split transfer', function () {
    receiptProviderSetup();
    $client = Client::factory()->create();
    $charges = Receivable::factory()->count(2)->for(Project::factory()->for($client), 'billable')->create(['amount' => 10001]);
    [$first, $firstInvoice] = receiptInvoice($charges[0], 101);
    [$second, $secondInvoice] = receiptInvoice($charges[1], 102, 'SimplifiedInvoice');
    $payment = app(RecordAllocatedPayment::class)->handle([
        'amount' => 10001, 'method' => PaymentMethod::Transfer, 'paid_on' => '2026-10-15',
    ], [$charges[0]->id => 6001, $charges[1]->id => 4000]);
    $remote = [];
    $payloads = [];

    Http::fake(function (Request $request) use (&$remote, &$payloads, $firstInvoice, $secondInvoice) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match ($path) {
            '/sequences.json' => Http::response(['sequences' => [receiptSeries()]]),
            '/invoices/101.json' => Http::response(['invoice' => $firstInvoice]),
            '/simplified_invoices/102.json' => Http::response(['simplified_invoice' => $secondInvoice]),
            '/document/101/related_documents.json' => Http::response(['documents' => array_values(array_filter([$remote[101] ?? null]))]),
            '/document/102/related_documents.json' => Http::response(['documents' => array_values(array_filter([$remote[102] ?? null]))]),
            '/documents/101/partial_payments.json', '/documents/102/partial_payments.json' => (function () use ($path, $request, &$remote, &$payloads) {
                $invoiceId = (int) explode('/', $path)[2];
                $body = $request['partial_payment'];
                $attempt = InvoiceXpressReceiptAttempt::query()->where('provider_invoice_id', $invoiceId)->sole();
                $payloads[$invoiceId] = ['body' => $body, 'committed_note' => $attempt->note];

                return Http::response(['receipt' => $remote[$invoiceId] = receiptResponse($invoiceId + 500, $body['note'], $body['amount'])]);
            })(),
            default => throw new LogicException($path),
        };
    });

    $service = app(InvoiceXpressReceipt::class);
    foreach ($payment->allocations()->orderBy('id')->get() as $allocation) {
        $preview = $service->preview($allocation->id);
        expect($preview['amount'])->toBe($allocation->amount);
        $attempt = $service->confirm($allocation->id, $preview['revision']);
        expect($service->confirm($allocation->id, $preview['revision'])->id)->toBe($attempt->id);
        $receipt = FiscalDocument::query()->findOrFail($attempt->fiscal_document_id);
        expect($receipt->type)->toBe(FiscalDocumentType::Receipt)
            ->and($receipt->total_amount)->toBe($allocation->amount)
            ->and($receipt->links->payment_id)->toBe($payment->id)
            ->and($receipt->links->related_fiscal_document_id)->toBe($allocation->receivable_id === $charges[0]->id ? $first->id : $second->id);
    }

    foreach ([101 => 60.01, 102 => 40.0] as $id => $amount) {
        expect($payloads[$id]['body'])->toEqual([
            'amount' => $amount,
            'payment_mechanism' => 'TB',
            'payment_date' => '15/10/2026',
            'serie' => 'A',
            'note' => $payloads[$id]['committed_note'],
        ]);
    }

    expect(InvoiceXpressReceiptAttempt::query()->distinct()->count('note'))->toBe(2);
    expect(Http::recorded(fn (Request $request) => $request->method() === 'POST'))->toHaveCount(2);
})->group('integration');

it('preserves an ambiguous intent and recovers only a unique verified related receipt without reposting', function () {
    receiptProviderSetup();
    [$allocation, , $invoice] = receiptAllocation();
    $related = [];
    $note = null;
    $posts = 0;
    Http::fake(function (Request $request) use ($invoice, &$related, &$note, &$posts) {
        return match (parse_url($request->url(), PHP_URL_PATH)) {
            '/sequences.json' => Http::response(['sequences' => [receiptSeries()]]),
            '/invoices/101.json' => Http::response(['invoice' => $invoice]),
            '/document/101/related_documents.json' => Http::response(['documents' => $related]),
            '/documents/101/partial_payments.json' => (function () use ($request, &$note, &$posts) {
                $posts++;
                $note = $request['partial_payment']['note'];
                throw new ConnectionException('Timeout');
            })(),
            default => throw new LogicException,
        };
    });

    $service = app(InvoiceXpressReceipt::class);
    $preview = $service->preview($allocation->id);
    $attempt = $service->confirm($allocation->id, $preview['revision']);
    expect($attempt->provider_receipt_id)->toBeNull()
        ->and($service->confirm($allocation->id, $preview['revision'])->id)->toBe($attempt->id);
    expect($service->recover($attempt->id)->fiscal_document_id)->toBeNull();

    $related = [receiptResponse(888, $note, 99.02)];
    expect($service->recover($attempt->id)->fiscal_document_id)->toBeNull();

    $related = [receiptResponse(888, $note, 99.01), receiptResponse(889, $note, 99.01)];
    expect($service->recover($attempt->id)->fiscal_document_id)->toBeNull();

    $related = [[...receiptResponse(888, $note, 99.01), 'sequence_id' => 17]];
    expect($service->recover($attempt->id)->fiscal_document_id)->toBeNull();

    $related = [[...receiptResponse(888, $note, 99.01), 'atcud' => 'N/D']];
    expect($service->recover($attempt->id)->fiscal_document_id)->toBeNull();

    $related = [receiptResponse(888, $note, 99.01)];
    $resolved = $service->recover($attempt->id);
    expect($resolved->provider_receipt_id)->toBe(888)
        ->and($resolved->fiscal_document_id)->not->toBeNull()
        ->and($service->recover($attempt->id)->fiscal_document_id)->toBe($resolved->fiscal_document_id);
    expect($posts)->toBe(1);
})->group('integration');

it('requires the server allowlist on confirmation even after a valid preview', function () {
    receiptProviderSetup();
    [$allocation, , $invoice] = receiptAllocation();
    Http::fake(['*/sequences.json*' => Http::response(['sequences' => [receiptSeries()]]), '*/invoices/101.json*' => Http::response(['invoice' => $invoice]), '*/related_documents.json*' => Http::response(['documents' => []])]);
    $service = app(InvoiceXpressReceipt::class);
    $preview = $service->preview($allocation->id);

    config(['services.invoicexpress.catalog_safe_accounts' => []]);
    expect(fn () => $service->confirm($allocation->id, $preview['revision']))->toThrow(ValidationException::class);
    expect(InvoiceXpressReceiptAttempt::query()->count())->toBe(0)
        ->and(Http::recorded(fn (Request $request) => $request->method() === 'POST'))->toBeEmpty();
})->group('integration');

it('blocks an inconsistent or unverified withholding amount on the provider invoice', function (array $retention) {
    receiptProviderSetup();
    [$allocation, , $invoice] = receiptAllocation();
    unset($invoice['retention']);
    Http::fake(['*/sequences.json*' => Http::response(['sequences' => [receiptSeries()]]), '*/invoices/101.json*' => Http::response(['invoice' => [...$invoice, ...$retention]])]);

    expect(fn () => app(InvoiceXpressReceipt::class)->preview($allocation->id))->toThrow(ValidationException::class, __('The withholding on the original invoice could not be verified.'));
    expect(InvoiceXpressReceiptAttempt::query()->count())->toBe(0);
})->with(['withheld' => [['retention' => '23']], 'missing' => [[]]])->group('integration');

it('rejects a provider invoice with a sub-cent total before preparing a receipt', function () {
    receiptProviderSetup();
    [$allocation, , $invoice] = receiptAllocation();
    $invoice['total'] = 100.01000000000002;
    Http::fake([
        '*/invoices/101.json*' => Http::response(['invoice' => $invoice]),
        '*/sequences.json*' => Http::response(['sequences' => [receiptSeries()]]),
        '*/related_documents.json*' => Http::response(['documents' => []]),
    ]);

    expect(fn () => app(InvoiceXpressReceipt::class)->preview($allocation->id))
        ->toThrow(ValidationException::class, 'não coincide com o registo local');
})->group('integration');

it('blocks an external receipt without a verified allocation and rejects a stale local preview', function () {
    receiptProviderSetup();
    [$allocation, , $invoice] = receiptAllocation();
    $related = [];
    Http::fake([
        '*/sequences.json*' => Http::response(['sequences' => [receiptSeries()]]), '*/invoices/101.json*' => Http::response(['invoice' => $invoice]),
        '*/related_documents.json*' => function () use (&$related) {
            return Http::response(['documents' => $related]);
        },
    ]);
    $service = app(InvoiceXpressReceipt::class);
    $preview = $service->preview($allocation->id);
    $allocation->payment->update(['amount' => 9000]);

    expect(fn () => $service->confirm($allocation->id, $preview['revision']))->toThrow(ValidationException::class, 'pré-visualizar');

    $related = [receiptResponse(999, 'manual', 10.00)];
    expect(fn () => $service->preview($allocation->id))->toThrow(ValidationException::class, 'movimentos');
    expect(InvoiceXpressReceiptAttempt::query()->count())->toBe(0);
})->group('integration');

it('requires the current account owner and scopes allocation IDs', function () {
    receiptProviderSetup();
    [$allocation] = receiptAllocation();
    $other = Account::factory()->create();
    $foreign = CurrentAccount::run($other, fn () => receiptAllocation()[0]);
    expect(fn () => app(InvoiceXpressReceipt::class)->preview($foreign->id))->toThrow(ModelNotFoundException::class);

    $accountant = User::factory()->create();
    $this->account->users()->attach($accountant, ['role' => 'accountant']);
    $this->actingAs($accountant);
    expect(fn () => app(InvoiceXpressReceipt::class)->preview($allocation->id))->toThrow(HttpException::class);
    Http::assertNothingSent();
})->group('integration');

it('opens the page in Receitas and emits through Livewire only after explicit confirmation', function () {
    receiptProviderSetup();
    [$allocation, , $invoice] = receiptAllocation();
    $related = [];
    Http::fake(function (Request $request) use ($invoice, &$related) {
        return match (parse_url($request->url(), PHP_URL_PATH)) {
            '/invoices/101.json' => Http::response(['invoice' => $invoice]),
            '/sequences.json' => Http::response(['sequences' => [receiptSeries()]]),
            '/document/101/related_documents.json' => Http::response(['documents' => $related]),
            '/documents/101/partial_payments.json' => (function () use ($request, &$related) {
                $body = $request['partial_payment'];
                $related[] = $receipt = receiptResponse(801, $body['note'], $body['amount']);

                return Http::response(['receipt' => $receipt]);
            })(),
            default => throw new LogicException,
        };
    });

    $this->get(InvoiceXpressReceiptPage::getUrl())->assertOk()->assertSee('Recibos');
    expect(InvoiceXpressReceiptPage::getNavigationItems())->toHaveCount(1);
    $page = Livewire::test(InvoiceXpressReceiptPage::class)
        ->assertCanSeeTableRecords([$allocation])
        ->callAction(TestAction::make('preview')->table($allocation))
        ->assertSee('A/101')
        ->assertSee('Série de recibos A')
        ->call('confirm')
        ->assertHasErrors('accepted');
    expect(InvoiceXpressReceiptAttempt::query()->count())->toBe(0);

    $page->set('accepted', true)->call('confirm')
        ->assertHasNoErrors()
        ->assertSee('Histórico de emissão')
        ->assertSee('ATCUD RCABC123-801');
    expect(InvoiceXpressReceiptAttempt::query()->sole()->fiscal_document_id)->not->toBeNull();
})->group('ui');

it('never sends the POST if the enclosing transaction rolls back', function () {
    receiptProviderSetup();
    [$allocation, , $invoice] = receiptAllocation();
    Http::fake([
        '*/sequences.json*' => Http::response(['sequences' => [receiptSeries()]]), '*/invoices/101.json*' => Http::response(['invoice' => $invoice]),
        '*/related_documents.json*' => Http::response(['documents' => []]),
    ]);
    $service = app(InvoiceXpressReceipt::class);
    $preview = $service->preview($allocation->id);

    DB::beginTransaction();
    try {
        $attempt = $service->confirm($allocation->id, $preview['revision']);
        expect($attempt->provider_receipt_id)->toBeNull();
    } finally {
        DB::rollBack();
    }

    expect(InvoiceXpressReceiptAttempt::query()->count())->toBe(0);
    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
})->group('integration');

it('uses verified prior receipts to calculate the remaining balance and refuses a second pending intent for the same invoice', function () {
    receiptProviderSetup();
    $charge = Receivable::factory()->create(['amount' => 10000]);
    [, $invoice] = receiptInvoice($charge, 101);
    $allocations = collect([6001, 3999, 1])->map(fn (int $amount) => Payment::factory()->for($charge)->create([
        'amount' => $amount, 'paid_on' => '2026-10-15', 'method' => PaymentMethod::Transfer,
    ])->allocations()->firstOrFail());
    $related = [];
    $posts = 0;
    Http::fake(function (Request $request) use ($invoice, &$related, &$posts) {
        return match (parse_url($request->url(), PHP_URL_PATH)) {
            '/sequences.json' => Http::response(['sequences' => [receiptSeries()]]),
            '/invoices/101.json' => Http::response(['invoice' => $invoice]),
            '/document/101/related_documents.json' => Http::response(['documents' => $related]),
            '/documents/101/partial_payments.json' => (function () use ($request, &$related, &$posts) {
                $posts++;
                $body = $request['partial_payment'];
                if ($posts === 2) {
                    throw new ConnectionException('Timeout');
                }

                $related[] = $receipt = receiptResponse(501, $body['note'], $body['amount']);

                return Http::response(['receipt' => $receipt]);
            })(),
            default => throw new LogicException,
        };
    });
    $service = app(InvoiceXpressReceipt::class);
    $first = $service->preview($allocations[0]->id);
    expect($service->confirm($allocations[0]->id, $first['revision'])->provider_receipt_id)->toBe(501);

    $allocations[1]->payment->update(['amount' => 4000]);
    expect(fn () => $service->preview($allocations[1]->id))->toThrow(ValidationException::class, 'saldo');
    $allocations[1]->payment->update(['amount' => 3999]);
    $second = $service->preview($allocations[1]->id);
    expect($service->confirm($allocations[1]->id, $second['revision'])->provider_receipt_id)->toBeNull();

    $third = $service->preview($allocations[2]->id);
    expect(fn () => $service->confirm($allocations[2]->id, $third['revision']))->toThrow(ValidationException::class, 'tentativa');
    expect($posts)->toBe(2);
})->group('integration');

it('refuses to attach a receipt after the original allocation has changed', function () {
    receiptProviderSetup();
    [$allocation, , $invoice] = receiptAllocation();
    $related = [];
    Http::fake(function (Request $request) use ($invoice, &$related) {
        return match (parse_url($request->url(), PHP_URL_PATH)) {
            '/sequences.json' => Http::response(['sequences' => [receiptSeries()]]),
            '/invoices/101.json' => Http::response(['invoice' => $invoice]),
            '/document/101/related_documents.json' => Http::response(['documents' => $related]),
            '/documents/101/partial_payments.json' => (function () use ($request, &$related) {
                $related = [receiptResponse(800, $request['partial_payment']['note'], 99.01)];
                throw new ConnectionException('Timeout');
            })(),
            default => throw new LogicException,
        };
    });
    $service = app(InvoiceXpressReceipt::class);
    $preview = $service->preview($allocation->id);
    $attempt = $service->confirm($allocation->id, $preview['revision']);
    $allocation->payment->update(['paid_on' => '2026-10-16']);

    expect($service->recover($attempt->id)->provider_receipt_id)->toBeNull()
        ->and(FiscalDocument::query()->where('type', FiscalDocumentType::Receipt)->count())->toBe(0);
})->group('integration');

it('blocks emission when the original invoice has no uniquely registered RC series', function (array $series) {
    receiptProviderSetup();
    [$allocation, , $invoice] = receiptAllocation();
    Http::fake([
        '*/invoices/101.json*' => Http::response(['invoice' => $invoice]),
        '*/sequences.json*' => Http::response(['sequences' => $series]),
        '*/related_documents.json*' => Http::response(['documents' => []]),
    ]);

    expect(fn () => app(InvoiceXpressReceipt::class)->preview($allocation->id))->toThrow(ValidationException::class, 'precisa de uma série de recibos registada');
    expect(InvoiceXpressReceiptAttempt::query()->count())->toBe(0);
    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
})->with([
    'unregistered receipt series' => fn () => [[...receiptSeries(), 'current_receipt_validation_code' => 'N/D']],
    'unmatched invoice sequence' => fn () => [[...receiptSeries(), 'current_invoice_sequence_id' => 91]],
    'ambiguous series name' => fn () => [receiptSeries(), [...receiptSeries(), 'id' => 99, 'current_invoice_sequence_id' => 91]],
])->group('integration');

it('links to local withholding reconciliation even with provider writes disabled', function () {
    receiptProviderSetup();
    config(['services.invoicexpress.catalog_safe_accounts' => []]);
    [$allocation] = receiptAllocation();
    $receipt = FiscalDocument::factory()->create([
        'type' => FiscalDocumentType::Receipt,
        'document_date' => '2026-10-15',
        'net_amount' => 10001,
        'vat_amount' => 0,
        'total_amount' => 10001,
    ]);
    $url = WithholdingReviewPage::getUrl(['allocation' => $allocation->id]);

    $this->get(InvoiceXpressReceiptPage::getUrl())->assertOk()->assertSee('Conciliar retenção')->assertSee($url);
    $this->get($url)->assertOk()->assertSee('Conciliar retenção com um recibo emitido')->assertSee($receipt->number);

    Livewire::withQueryParams(['allocation' => $allocation->id])->test(WithholdingReviewPage::class)
        ->set('documentId', (string) $receipt->id)
        ->set('retained', '1,00')
        ->call('record')
        ->assertHasErrors('accepted')
        ->set('accepted', true)
        ->call('record')
        ->assertHasNoErrors();

    expect($allocation->receivable->outstanding)->toBe(0)
        ->and($allocation->refresh()->amount)->toBe(9901)
        ->and(WithholdingReview::forYear(2026)->retainedTotal)->toBe(100);
    Http::assertNothingSent();
})->group('ui');

it('previews and reconciles an already issued ordinary partial receipt entirely locally', function () {
    actingAsAdmin();
    [$allocation, $invoice] = receiptAllocation();
    $receipt = FiscalDocument::factory()->create([
        'type' => FiscalDocumentType::Receipt, 'document_date' => '2026-10-15',
        'net_amount' => 9901, 'vat_amount' => 0, 'total_amount' => 9901,
    ]);
    expect(WithholdingReview::previewAllocation($allocation))->toMatchArray([
        'invoice' => $invoice->number, 'cash' => 9901, 'availableGross' => 10001,
    ]);
    $url = WithholdingReviewPage::getUrl(['allocation' => $allocation->id, 'ordinary' => true]);
    $this->get($url)->assertOk()->assertSee('Conciliar recibo emitido')->assertSee($receipt->number);
    Livewire::withQueryParams(['allocation' => $allocation->id, 'ordinary' => true])->test(WithholdingReviewPage::class)
        ->set('documentId', (string) $receipt->id)
        ->set('accepted', true)
        ->call('record')
        ->assertHasNoErrors();

    WithholdingReview::reconcileReceipt($receipt->id, $allocation->id, 0);
    $duplicate = $receipt->replicate()->fill(['number' => 'RC duplicado']);
    $duplicate->save();
    expect(fn () => WithholdingReview::reconcileReceipt($duplicate->id, $allocation->id, 0))->toThrow(ValidationException::class)
        ->and($receipt->fresh()->links->related_fiscal_document_id)->toBe($invoice->id)
        ->and($allocation->receivable->outstanding)->toBe(100);
    $this->assertDatabaseEmpty('withholding_records');
    Http::assertNothingSent();
})->group('ui');

it('offers local reconciliation for a manually issued final invoice after an allocated signal', function () {
    receiptProviderSetup();
    $client = Client::factory()->create();
    $charge = Receivable::factory()->for(Project::factory()->for($client), 'billable')->create(['amount' => 10000]);
    $advances = app(ManageCustomerAdvance::class);
    $signal = $advances->record($client->id, 2000, '2026-10-01', PaymentMethod::Transfer);
    $advances->apply($signal->id, $charge->id, '2026-10-02');
    $advance = FiscalDocument::factory()->create([
        'type' => FiscalDocumentType::InvoiceReceipt, 'document_date' => '2026-10-01',
        'net_amount' => 2000, 'vat_amount' => 0, 'total_amount' => 2000,
    ]);
    $advance->links()->create(['payment_id' => $signal->id]);
    $invoice = FiscalDocument::factory()->create([
        'document_date' => '2026-10-02', 'net_amount' => 8000, 'vat_amount' => 0, 'total_amount' => 8000,
    ]);
    $invoice->links()->create(['receivable_id' => $charge->id, 'related_fiscal_document_id' => $advance->id]);
    $payment = Payment::factory()->for($charge)->create(['amount' => 8000, 'paid_on' => '2026-10-03']);
    $allocation = $payment->allocations()->sole();

    Livewire::test(InvoiceXpressReceiptPage::class)->assertCanSeeTableRecords([$allocation])
        ->assertCanNotSeeTableRecords([$signal->allocations()->sole()]);
    $service = app(InvoiceXpressReceipt::class);
    expect(WithholdingReview::previewAllocation($allocation))->toMatchArray([
        'invoice' => $invoice->number, 'cash' => 8000, 'availableGross' => 8000,
    ]);
    expect(fn () => $service->preview($allocation->id))->toThrow(ValidationException::class, 'concilia localmente');
    expect(fn () => $service->confirm($allocation->id, 'stale'))->toThrow(ValidationException::class, 'concilia localmente');
    $this->assertDatabaseEmpty('invoice_xpress_receipt_attempts');
    Http::assertNothingSent();
})->group('ui');

function retainedReceiptAllocation(bool $split = false): array
{
    $client = Client::factory()->create();
    $charge = Receivable::factory()->for(Project::factory()->for($client), 'billable')->create(['amount' => 10001]);
    [$original, $invoice] = receiptInvoice($charge, 101);
    $invoice['retention'] = '23.00';
    $invoice['total'] = '77.01';
    $terms = ['paid_on' => '2026-10-15', 'method' => PaymentMethod::Transfer];
    if ($split) {
        $other = Receivable::factory()->for(Project::factory()->for($client), 'billable')->create(['amount' => 10000]);
        $payment = app(RecordAllocatedPayment::class)->handle([...$terms, 'amount' => 3000], [$charge->id => 2567, $other->id => 433]);
    } else {
        $payment = Payment::factory()->for($charge)->create([...$terms, 'amount' => 2567]);
    }

    return [$payment->allocations()->where('receivable_id', $charge->id)->sole(), $original, $invoice];
}

it('previews withholding for one allocation of a split transfer without enabling an undocumented POST', function () {
    receiptProviderSetup();
    config(['services.invoicexpress.withholding_validated_accounts' => ['receipt-test']]);
    [$allocation, , $invoice] = retainedReceiptAllocation(split: true);
    Http::fake([
        '*/invoices/101.json*' => Http::response(['invoice' => $invoice]),
        '*/sequences.json*' => Http::response(['sequences' => [receiptSeries()]]),
        '*/related_documents.json*' => Http::response(['documents' => []]),
    ]);
    $service = app(InvoiceXpressReceipt::class);
    $preview = $service->preview($allocation->id);

    expect($preview['amount'])->toBe(3334)
        ->and($preview['withholding'])->toMatchArray(['cash' => 2567, 'retained' => 767, 'gross' => 3334])
        ->and($preview['canIssue'])->toBeFalse();
    Livewire::test(InvoiceXpressReceiptPage::class)->call('preview', $allocation->id)
        ->assertHasNoErrors()
        ->assertSee(__('Gross settlement'))
        ->assertSee(__('Issuing receipts with withholding requires validation in the InvoiceXpress sandbox.'))
        ->assertDontSee(__('Issue receipt'));
    expect(fn () => $service->confirm($allocation->id, $preview['revision']))->toThrow(ValidationException::class);
    $this->assertDatabaseEmpty('invoice_xpress_receipt_attempts');
    Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');

    config(['services.invoicexpress.catalog_safe_accounts' => []]);
    Livewire::test(InvoiceXpressReceiptPage::class)
        ->assertActionVisible(TestAction::make('preview')->table($allocation))
        ->callAction(TestAction::make('preview')->table($allocation))->assertHasNoErrors()
        ->assertSee(__('Gross settlement'))->assertDontSee(__('Issue receipt'));
    Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
})->group('integration');

it('uses confirmed local withheld receipts for cumulative rounding and never duplicates their allocation', function () {
    receiptProviderSetup();
    [$firstAllocation, $original, $invoice] = retainedReceiptAllocation();
    $receipt = FiscalDocument::factory()->create([
        'type' => FiscalDocumentType::Receipt, 'number' => 'A/501', 'origin' => 'InvoiceXpress',
        'document_date' => '2026-10-15', 'net_amount' => 3334, 'vat_amount' => 0, 'total_amount' => 3334,
    ]);
    WithholdingReview::reconcileReceipt($receipt->id, $firstAllocation->id, 767);
    InvoiceXpressSnapshot::query()->create([
        'subdomain' => 'receipt-test', 'provider_id' => 501, 'source_hash' => str_repeat('b', 64),
        'source' => ['provider_type' => 'Receipt'], 'fiscal_document_id' => $receipt->id,
    ]);
    $second = Payment::factory()->for($firstAllocation->receivable)->create([
        'amount' => 2567, 'paid_on' => '2026-10-15', 'method' => PaymentMethod::Transfer,
    ])->allocations()->sole();
    $remoteReceipt = [...receiptResponse(501, 'Previously issued', 25.67), 'retention' => '7.67', 'before_taxes' => '33.34'];
    Http::fake([
        '*/invoices/101.json*' => Http::response(['invoice' => $invoice]),
        '*/sequences.json*' => Http::response(['sequences' => [receiptSeries()]]),
        '*/related_documents.json*' => Http::response(['documents' => [$remoteReceipt]]),
    ]);

    $service = app(InvoiceXpressReceipt::class);
    $preview = $service->preview($second->id);
    expect($preview['amount'])->toBe(3333)
        ->and($preview['withholding'])->toMatchArray(['cash' => 2567, 'retained' => 766, 'gross' => 3333]);
    expect(fn () => $service->preview($firstAllocation->id))->toThrow(ValidationException::class);

    $receipt->update(['net_amount' => 3335, 'total_amount' => 3335]);
    expect(fn () => $service->preview($second->id))->toThrow(ValidationException::class);
    Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
})->group('integration');

it('recovers a retained receipt only after exact provider readback and persists its gross settlement once', function () {
    receiptProviderSetup();
    [$allocation, $original, $invoice] = retainedReceiptAllocation();
    $related = [];
    Http::fake(function (Request $request) use ($invoice, &$related) {
        return match (parse_url($request->url(), PHP_URL_PATH)) {
            '/invoices/101.json' => Http::response(['invoice' => $invoice]),
            '/sequences.json' => Http::response(['sequences' => [receiptSeries()]]),
            '/document/101/related_documents.json' => Http::response(['documents' => $related]),
            default => throw new LogicException($request->url()),
        };
    });
    $service = app(InvoiceXpressReceipt::class);
    $attempt = new InvoiceXpressReceiptAttempt;
    $attempt->forceFill([
        'confirmed_by' => auth()->id(), 'payment_allocation_id' => $allocation->id,
        'payment_id' => $allocation->payment_id, 'receivable_id' => $allocation->receivable_id,
        'subdomain' => 'receipt-test', 'provider_invoice_id' => 101, 'note' => 'Previously submitted retained receipt',
        'pending_target' => hash('sha256', 'retained-receipt'),
    ]);
    // The persisted intent represents an earlier authorized submission, never a new POST.
    $attempt->intent = [
        'local' => [
            'accountId' => CurrentAccount::id(), 'allocationId' => $allocation->id, 'receivableId' => $allocation->receivable_id,
            'paymentId' => $allocation->payment_id, 'paymentAmount' => 2567, 'paidOn' => '15/10/2026', 'paidOnIso' => '2026-10-15',
            'charge' => $allocation->receivable->label, 'amount' => 2567, 'invoiceId' => $original->id, 'invoiceNumber' => 'A/101',
            'invoiceDate' => '01/10/2026', 'invoiceTotal' => 10001, 'invoiceNet' => 10001, 'invoiceVat' => 0,
            'partyName' => $original->party_name, 'partyTaxId' => $original->party_tax_id, 'subdomain' => 'receipt-test',
            'providerInvoiceId' => 101, 'providerType' => 'Invoice', 'snapshotId' => InvoiceXpressSnapshot::query()->where('provider_id', 101)->sole()->id,
            'sourceHash' => str_repeat('a', 64),
        ],
        'clientId' => 83, 'series' => ['id' => 16, 'name' => 'A', 'validationCode' => 'RCABC123'], 'relatedReceipts' => [],
        'withholding' => ['cash' => 2567, 'retained' => 767, 'gross' => 3334, 'invoiceRetained' => 2300, 'invoiceCash' => 7701],
    ];
    $attempt->save();
    $related = [[...receiptResponse(501, $attempt->note, 25.67), 'before_taxes' => '33.34', 'retention' => '7.66']];
    expect($service->recover($attempt->id)->fiscal_document_id)->toBeNull();
    $related[0]['retention'] = '7.67';
    $related[] = ['id' => 999, 'type' => 'DebitNote', 'status' => 'final'];
    expect($service->recover($attempt->id)->fiscal_document_id)->toBeNull();
    array_pop($related);
    $resolved = $service->recover($attempt->id);
    expect($resolved->provider_receipt_id)->toBe(501);
    $receipt = FiscalDocument::query()->findOrFail($resolved->fiscal_document_id);
    expect($receipt->total_amount)->toBe(3334)
        ->and($receipt->net_amount)->toBe(3334)
        ->and($receipt->links->payment_id)->toBe($allocation->payment_id)
        ->and($receipt->links->related_fiscal_document_id)->toBe($original->id)
        ->and(WithholdingReview::forYear(2026)->retainedTotal)->toBe(767)
        ->and($allocation->receivable->fresh()->outstanding)->toBe(6667)
        ->and($service->recover($attempt->id)->fiscal_document_id)->toBe($receipt->id);
    $this->assertDatabaseCount('withholding_records', 1);
    Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
})->group('integration');

it('accepts a header retention rate only when supported by the recorded invoice terms', function () {
    receiptProviderSetup();
    $charge = Receivable::factory()->create(['amount' => 20000]);
    [, $invoice] = receiptInvoice($charge, 101);
    $invoice['retention'] = '23.0';
    $invoice['total'] = '154.00';
    $allocation = Payment::factory()->for($charge)->create([
        'amount' => 15400, 'paid_on' => '2026-10-15', 'method' => PaymentMethod::Transfer,
    ])->allocations()->sole();
    Http::fake([
        '*/invoices/101.json*' => Http::response(['invoice' => $invoice]),
        '*/sequences.json*' => Http::response(['sequences' => [receiptSeries()]]),
        '*/related_documents.json*' => Http::response(['documents' => []]),
    ]);
    $service = app(InvoiceXpressReceipt::class);
    expect(fn () => $service->preview($allocation->id))->toThrow(ValidationException::class);

    $snapshot = InvoiceXpressSnapshot::query()->where('provider_id', 101)->sole();
    $snapshot->update(['source' => [...$snapshot->source, 'withholding' => [
        'basisPoints' => 2300, 'gross' => 20000, 'retained' => 4600, 'cash' => 15400,
    ]]]);
    expect($service->preview($allocation->id)['withholding'])->toMatchArray([
        'cash' => 15400, 'retained' => 4600, 'gross' => 20000,
    ]);
})->group('integration');
