<?php

use App\Actions\LinkInvoiceAdvance;
use App\Enums\FiscalDocumentType;
use App\Enums\FiscalProfileViesStatus;
use App\Filament\Pages\InvoiceXpressIssuePage;
use App\Models\FiscalDocument;
use App\Models\FiscalProfile;
use App\Models\InvoiceXpressIssueAttempt;
use App\Models\Payment;
use App\Models\WithholdingRecord;
use App\Support\ClientStatement;
use App\Support\InvoiceXpressIssue;
use App\Support\WithholdingReview;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

pest()->group('invoicexpress');

it('records effective withholding only when a verified invoice receipt is issued against actual cash', function (bool $receipt, string $providerRate, string $providerTotal) {
    $charge = invoiceXpressIssueReceivable();
    config(['services.invoicexpress.withholding_validated_accounts' => ['pilot']]);
    $payment = $receipt ? Payment::factory()->for($charge)->create(['amount' => 30015, 'paid_on' => today()]) : null;
    $type = $receipt ? 'invoice_receipt' : 'invoice';
    $final = false;
    invoiceXpressIssueFake(function (Request $request) use ($receipt, $type, $providerRate, $providerTotal, &$final) {
        if ($request->method() === 'POST') {
            return Http::response([$type => ['id' => 65]], 201);
        }
        if ($request->method() === 'PUT') {
            $final = true;

            return Http::response('', 204);
        }

        $document = $receipt ? invoiceXpressIssueReceiptResponse($final ? 'settled' : 'draft') : invoiceXpressIssueResponse($final ? 'final' : 'draft');
        $document[$type]['retention'] = $providerRate;
        $document[$type]['total'] = $providerTotal;

        return Http::response($document);
    });
    $issue = app(InvoiceXpressIssue::class);
    $preview = $issue->preview($charge->id, 42, 8, 3, documentType: $type, withholdingBasisPoints: 2300);
    $attempt = $issue->confirm($charge->id, 42, 8, 3, $preview['revision'], documentType: $type, withholdingBasisPoints: 2300);
    if ($providerRate !== '23.00' || $providerTotal !== '300.15') {
        expect($attempt->state)->toBe(InvoiceXpressIssueAttempt::Blocked);
        $this->assertDatabaseEmpty('withholding_records');
        $this->assertDatabaseEmpty('fiscal_documents');
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');

        return;
    }
    expect($attempt->state)->toBe(InvoiceXpressIssueAttempt::Finalized);
    expect(FiscalDocument::sole()->total_amount)->toBe(36918);
    expect($issue->recover($attempt->id)->id)->toBe($attempt->id);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request[$type]['retention'] === '23.00');
    if ($receipt) {
        expect(WithholdingRecord::sole()->retained_amount)->toBe(6903)
            ->and($charge->fresh()->outstanding)->toBe(0)
            ->and($payment->fresh()->amount)->toBe(30015);
    } else {
        $this->assertDatabaseEmpty('withholding_records');
        expect($charge->fresh()->outstanding)->toBe(36918);
    }
})->with([
    'invoice' => [false, '23.00', '300.15'],
    'invoice receipt' => [true, '23.00', '300.15'],
    'provider draft has a different rate' => [true, '11.50', '300.15'],
    'provider draft differs by one cent' => [true, '23.00', '300.16'],
])->group('integration');

it('records the confirmed fiscal evidence of an EU service even if VIES changes after finalization', function (bool $uncertain) {
    $charge = invoiceXpressIssueReceivable();
    FiscalProfile::factory()->create(['effective_from' => '2026-10-01', 'vat_regime' => 'Normal mensal', 'vat_exempt' => false]);
    $charge->client->update(['fiscal_country_code' => 'ES', 'vat_number' => 'ESA12345678']);
    $charge->client->forceFill(['vat_validation_status' => FiscalProfileViesStatus::Valid, 'vat_validated_at' => now()])->save();
    $charge->billable->update(['vat_rate' => '0.0000']);
    $charge->update(['amount' => 30015]);
    $client = ['id' => 42, 'name' => 'Cliente Teste', 'country' => 'Spain', 'fiscal_id' => 'ESA12345678'];
    $tax = ['id' => 10, 'name' => 'Isento', 'region' => 'PT', 'code' => 'ISE', 'value' => 0];
    $final = false;
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($client, $tax, &$final, $uncertain) {
        return match (parse_url($request->url(), PHP_URL_PATH)) {
            '/clients/42.json' => Http::response(['client' => $client]),
            '/taxes.json' => Http::response(['taxes' => [$tax]]),
            '/items/8.json' => Http::response(['item' => [
                'id' => 8, 'name' => 'Consultoria', 'description' => 'Serviço', 'unit' => 'service', 'unit_price' => '100.05', 'tax' => $tax,
            ]]),
            '/sequences/19.json' => Http::response(['sequence' => [
                'id' => 19, 'serie' => 'PILOTO', 'current_invoice_sequence_id' => 19, 'current_invoice_validation_code' => 'ABCD1234',
            ]]),
            '/invoices.json' => Http::response(['invoice' => ['id' => 65]]),
            '/invoices/65/change-state.json' => tap(Http::response(['invoice' => ['id' => 65]]), function () use (&$final, $uncertain) {
                $final = true;
                if ($uncertain) {
                    throw new ConnectionException('Response lost');
                }
            }),
            '/invoices/65.json' => Http::response(invoiceXpressIssueResponse($final ? 'final' : 'draft', [
                'client' => $client, 'before_taxes' => 300.15, 'taxes' => 0, 'total' => 300.15, 'tax_exemption' => 'M40',
                'observations' => 'Data da prestação do serviço: 09/11/2026.',
                'items' => [[
                    'name' => 'Consultoria', 'description' => 'Serviço', 'unit' => 'service', 'unit_price' => '100.05',
                    'quantity' => 3, 'subtotal' => 300.15, 'tax_amount' => 0, 'total' => 300.15, 'tax' => $tax,
                ]],
            ])),
        };
    });

    $service = app(InvoiceXpressIssue::class);
    $preview = $service->preview($charge->id, 42, 8, 3, euOperationOn: '2026-11-09');
    expect($preview['totals'])->toBe(['net' => 30015, 'tax' => 0, 'total' => 30015]);
    $attempt = $service->confirm($charge->id, 42, 8, 3, $preview['revision'], euOperationOn: '2026-11-09');
    if ($uncertain) {
        expect($attempt->state)->toBe(InvoiceXpressIssueAttempt::Draft);
        $charge->client->forceFill(['vat_validation_status' => FiscalProfileViesStatus::Unavailable, 'vat_validated_at' => now()])->save();
        config(['services.invoicexpress.catalog_safe_accounts' => []]);
        $attempt = $service->recover($attempt->id);
    }
    expect($attempt->state)->toBe(InvoiceXpressIssueAttempt::Finalized);
    $evidence = FiscalDocument::sole()->fiscalOperationEvidence;
    expect($evidence->operation_on->toDateString())->toBe('2026-11-09')
        ->and($evidence->vat_number)->toBe('ESA12345678')
        ->and($evidence->net_amount)->toBe(30015);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request['invoice']['tax_exemption_reason'] === 'M40'
        && $request['invoice']['observations'] === 'Data da prestação do serviço: 09/11/2026.');
})->with(['immediate finalization' => false, 'recovery after VIES changes' => true])->group('integration');

it('finalizes an eligible simplified service invoice using its own registered series and reference', function () {
    $receivable = invoiceXpressIssueReceivable();
    $receivable->update(['amount' => 9840]);
    $final = false;
    invoiceXpressIssueFake(function (Request $request) use (&$final) {
        $method = $request->method();
        if ($method === 'POST') {
            return Http::response(['simplified_invoice' => ['id' => 65]], 201);
        }
        if ($method === 'PUT') {
            $final = true;

            return Http::response(['simplified_invoice' => ['id' => 65]]);
        }
        $invoice = invoiceXpressIssueResponse($final ? 'final' : 'draft', [
            'type' => 'SimplifiedInvoice', 'sequence_id' => 22, 'atcud' => 'FSCD1234-7',
            'before_taxes' => 80, 'taxes' => 18.40, 'total' => 98.40,
        ])['invoice'];
        $invoice['items'][0] = array_replace($invoice['items'][0], [
            'unit_price' => 80, 'quantity' => 1, 'subtotal' => 80, 'tax_amount' => 18.40, 'total' => 98.40,
        ]);

        return Http::response(['simplified_invoice' => $invoice]);
    }, itemPrice: '80');
    $service = app(InvoiceXpressIssue::class);
    $preview = $service->preview($receivable->id, 42, 8, 1, documentType: 'simplified_invoice');
    expect($preview['sequence']['id'])->toBe(22);
    $attempt = $service->confirm($receivable->id, 42, 8, 1, $preview['revision'], documentType: 'simplified_invoice');

    expect($attempt->state)->toBe(InvoiceXpressIssueAttempt::Finalized)
        ->and($attempt->document_reference)->toBe('FS PILOTO/7');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/simplified_invoices.json?')
        && $request['simplified_invoice']['sequence_id'] === '22');
})->group('integration');

it('rejects a taxable simplified service invoice above one hundred euros', function () {
    $receivable = invoiceXpressIssueReceivable();
    invoiceXpressIssueFake(fn () => Http::response([], 503));

    expect(fn () => app(InvoiceXpressIssue::class)->preview($receivable->id, 42, 8, 3, documentType: 'simplified_invoice'))
        ->toThrow(ValidationException::class, '100,00 €');
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
})->group('integration');

it('previews a domestic article 53 invoice with the expressly chosen M10 and the existing zero-rate catalogue', function () {
    $receivable = invoiceXpressIssueReceivable();
    $receivable->update(['amount' => 30015]);
    $receivable->billable->update(['vat_rate' => 0]);
    FiscalProfile::factory()->create(['vat_exempt' => true, 'country_code' => 'PT']);
    Http::preventStrayRequests();
    Http::fake([
        '*/clients/42.json?*' => Http::response(['client' => ['id' => 42, 'name' => 'Cliente Teste', 'country' => 'Portugal', 'fiscal_id' => '508025338']]),
        '*/items/8.json?*' => Http::response(['item' => ['id' => 8, 'name' => 'Consultoria', 'unit' => 'service', 'unit_price' => '100.0500',
            'tax' => ['id' => 9, 'name' => 'IVA Contiu', 'value' => 0]]]),
        '*/taxes.json?*' => Http::response(['taxes' => [['id' => 9, 'name' => 'IVA Contiu', 'region' => 'PT', 'code' => 'ISE', 'value' => 0]]]),
        '*/sequences/19.json?*' => Http::response(['sequence' => ['id' => 19, 'serie' => 'PILOTO',
            'current_invoice_sequence_id' => 19, 'current_invoice_validation_code' => 'ABCD1234']]),
    ]);
    $issue = app(InvoiceXpressIssue::class);

    expect(fn () => $issue->preview($receivable->id, 42, 8, 3))->toThrow(ValidationException::class);
    $preview = $issue->preview($receivable->id, 42, 8, 3, exemptionReason: 'M10');
    expect($preview['invoice']['tax_exemption_reason'])->toBe('M10')
        ->and($preview['totals'])->toBe(['net' => 30015, 'tax' => 0, 'total' => 30015]);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
})->group('integration');

it('settles a fatura-recibo and links the existing payment exactly once after uncertain finalization', function () {
    $receivable = invoiceXpressIssueReceivable();
    $payment = Payment::factory()->for($receivable)->create(['amount' => $receivable->amount]);
    $settled = false;
    $finalizations = [];
    invoiceXpressIssueFake(function (Request $request) use (&$settled, &$finalizations) {
        return match ($request->method()) {
            'POST' => Http::response(['invoice_receipt' => ['id' => 65]], 201),
            'PUT' => (function () use ($request, &$settled, &$finalizations) {
                $finalizations[] = [parse_url($request->url(), PHP_URL_PATH), $request->data()];
                $settled = true;
                throw new ConnectionException('Fake timeout');
            })(),
            'GET' => Http::response(invoiceXpressIssueReceiptResponse($settled ? 'settled' : 'draft')),
        };
    });

    $page = Livewire::test(InvoiceXpressIssuePage::class)
        ->set('documentType', 'invoice_receipt')
        ->set('receivableId', (string) $receivable->id)
        ->set('customerId', '42')
        ->set('itemId', '8')
        ->set('quantity', '3')
        ->call('preview')
        ->assertHasNoErrors()
        ->assertSet('previewData.payment.id', $payment->id)
        ->assertSee('Fatura-recibo');

    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    $page->set('accepted', true)->call('confirm')->assertHasNoErrors();

    $attempt = InvoiceXpressIssueAttempt::sole();
    expect($attempt->state)->toBe(InvoiceXpressIssueAttempt::Draft);
    $this->assertDatabaseEmpty('fiscal_documents');

    // Read-back and local reconciliation remain available after provider writes are disabled.
    config(['services.invoicexpress.catalog_safe_accounts' => []]);
    $page->call('recover', $attempt->id)->assertHasNoErrors()->assertSee('FR PILOTO/7');
    $page->call('recover', $attempt->id)->assertHasNoErrors();

    $document = FiscalDocument::sole();
    expect($document->type->value)->toBe('invoice_receipt')
        ->and($document->links->payment_id)->toBe($payment->id)
        ->and($document->links->receivable_id)->toBe($receivable->id)
        ->and($receivable->fresh()->outstanding)->toBe(0)
        ->and($attempt->fresh()->state)->toBe(InvoiceXpressIssueAttempt::Finalized);
    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseCount('payment_allocations', 1);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && parse_url($request->url(), PHP_URL_PATH) === '/invoice_receipts.json'
        && $request['invoice_receipt']['sequence_id'] === '25');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && parse_url($request->url(), PHP_URL_PATH) === '/invoice_receipts/65.json');
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(1);
    expect($finalizations)->toBe([['/invoice_receipts/65/change-state.json', ['invoice' => ['state' => 'finalized']]]]);
})->group('ui');

it('rejects an ineligible receipt payment before contacting the provider', function (array $changes) {
    $receivable = invoiceXpressIssueReceivable();
    Payment::factory()->for($receivable)->create($changes + ['amount' => $receivable->amount]);
    invoiceXpressIssueFake(fn () => Http::response([], 503));

    Livewire::test(InvoiceXpressIssuePage::class)
        ->set('documentType', 'invoice_receipt')
        ->set('receivableId', (string) $receivable->id)
        ->set('customerId', '42')
        ->set('itemId', '8')
        ->set('quantity', '3')
        ->call('preview')
        ->assertHasErrors(['payment']);

    Http::assertNothingSent();
    $this->assertDatabaseEmpty('invoice_xpress_issue_attempts');
})->with([
    'partial payment' => [['amount' => 36917]],
    'older payment' => [['paid_on' => '2026-11-09']],
    'previous invoice reference' => [['invoice_number' => 'FT PREV/1']],
])->group('ui');

it('deducts the gross signal and reconciles the separate IRS amounts on the signal and final payment', function () {
    $charge = invoiceXpressIssueReceivable();
    $charge->update(['amount' => 123000]);
    $advance = invoiceXpressAllocatedAdvance($charge, net: 30000, vat: 6900, retained: 6900);
    config(['services.invoicexpress.catalog_safe_accounts' => []]);
    invoiceXpressIssueFake(fn () => throw new LogicException('Unexpected remote document call.'), itemPrice: '1000');

    Livewire::test(InvoiceXpressIssuePage::class)
        ->set('receivableId', (string) $charge->id)
        ->set('customerId', '42')->set('itemId', '8')->set('quantity', '1')->set('withholdingRate', '2300')
        ->call('preview')->assertHasNoErrors()
        ->assertSet('previewData.advanceSettlement.received', ['cash' => 30000, 'retained' => 6900])
        ->assertSet('previewData.advanceSettlement.balance', ['net' => 70000, 'tax' => 16100, 'total' => 86100])
        ->assertSet('previewData.withholding', ['basisPoints' => 2300, 'gross' => 86100, 'retained' => 16100, 'cash' => 70000])
        ->assertDontSee('Confirmar e emitir');

    $final = FiscalDocument::factory()->create([
        'type' => FiscalDocumentType::Invoice, 'document_date' => today(), 'party_tax_id' => '508025338',
        'net_amount' => 70000, 'vat_amount' => 16100, 'total_amount' => 86100,
    ]);
    $final->links()->create(['receivable_id' => $charge->id]);
    app(LinkInvoiceAdvance::class)->handle($final->id, $advance->id);

    $payment = Payment::factory()->for($charge)->create(['amount' => 70000, 'paid_on' => today()]);
    $receipt = FiscalDocument::factory()->create([
        'type' => FiscalDocumentType::Receipt, 'document_date' => today(), 'party_tax_id' => '508025338',
        'net_amount' => 70000, 'vat_amount' => 16100, 'total_amount' => 86100,
    ]);
    $receipt->links()->create(['related_fiscal_document_id' => $final->id]);
    WithholdingReview::record($receipt->id, 16100, allocationId: $payment->allocations()->sole()->id);

    expect(WithholdingReview::forYear(2026)->retainedTotal)->toBe(23000)
        ->and((int) Payment::sum('amount'))->toBe(100000)
        ->and($charge->fresh()->outstanding)->toBe(0)
        ->and((new ClientStatement($charge->client))->balance())->toBe(0);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
})->group('ui');

it('blocks direct confirmation and recovery of a pending gross invoice after an advance is allocated even on an allowlisted account', function () {
    $receivable = invoiceXpressIssueReceivable();
    invoiceXpressIssueFake(fn () => Http::response([], 503));
    $issue = app(InvoiceXpressIssue::class);
    $preview = $issue->preview($receivable->id, 42, 8, 3);
    $attempt = $issue->confirm($receivable->id, 42, 8, 3, $preview['revision']);
    invoiceXpressAllocatedAdvance($receivable, documented: false);
    $calls = Http::recorded()->count();

    expect(fn () => $issue->confirm($receivable->id, 42, 8, 3, $preview['revision']))
        ->toThrow(ValidationException::class, 'A emissão do acerto pela API está bloqueada');
    expect($issue->recover($attempt->id)->state)->toBe(InvoiceXpressIssueAttempt::Blocked)
        ->and(Http::recorded())->toHaveCount($calls);
    $this->assertDatabaseCount('invoice_xpress_issue_attempts', 1);
    $this->assertDatabaseEmpty('fiscal_documents');
})->group('integration');

it('requires an unambiguous original invoice at the service VAT rate before preparing the adjustment', function (int $net, int $vat, bool $documented) {
    $receivable = invoiceXpressIssueReceivable();
    invoiceXpressAllocatedAdvance($receivable, $net, $vat, $documented);
    Http::preventStrayRequests();

    try {
        app(InvoiceXpressIssue::class)->preview($receivable->id, 42, 8, 3);
        test()->fail('The advance was not rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('advanceSettlement');
    }

    Http::assertNothingSent();
})->with([
    'missing fiscal document' => [10005, 2301, false],
    'different VAT rate' => [10005, 1301, true],
])->group('integration');
