<?php

use App\Exceptions\InvoiceXpressException;
use App\Models\Account;
use App\Models\FiscalDocumentDraft;
use App\Models\InvoiceXpressConfig;
use App\Models\InvoiceXpressSnapshot;
use App\Support\CurrentAccount;
use App\Support\InvoiceXpressDraftSync;
use App\Support\InvoiceXpressImport;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

pest()->group('invoicexpress', 'integration');

beforeEach(function () {
    $this->charge = invoiceXpressIssueReceivable();
    Http::preventStrayRequests();
});

function boundInvoiceXpressDraft(array $overrides = []): FiscalDocumentDraft
{
    $line = [
        'name' => 'Consultoria', 'description' => 'Serviço', 'unit' => 'service',
        'unit_price_cents' => 10005, 'quantity' => 3, 'tax' => ['name' => 'IVA Contiu'],
    ];
    $totals = ['net' => 30015, 'tax' => 6903, 'total' => 36918];

    $sendKey = (string) Str::uuid();
    test()->invoiceXpressIssueReference = 'Contiu draft '.$sendKey;

    return FiscalDocumentDraft::factory()->create([
        'send_key' => $sendKey,
        'provider_account' => 'pilot',
        'remote_id' => 65,
        'state' => FiscalDocumentDraft::Remote,
        'preview' => [
            'invoice' => [
                'date' => '10/11/2026', 'due_date' => '10/12/2026', 'reference' => 'Contiu #'.test()->charge->id,
                'client' => ['id' => 42], 'items' => [$line],
            ],
            'receivableId' => test()->charge->id,
            'customer' => ['id' => 42, 'name' => 'Cliente Teste', 'nif' => '508025338'],
            'sequence' => ['id' => 19, 'name' => 'PILOTO'],
            'tax' => ['id' => 9, 'name' => 'IVA Contiu', 'basisPoints' => 2300],
            'item' => ['unitCents' => 10005],
            'totals' => $totals,
        ],
        ...$overrides,
    ]);
}

function fakeInvoiceXpressDraftRead(string $status, array $overrides = []): void
{
    Http::fake([
        'https://pilot.app.invoicexpress.com/invoices/65.json?*' => Http::response(invoiceXpressIssueResponse($status, $overrides)),
    ]);
}

it('reads only the bound draft and promotes it once after external finalization', function () {
    $draft = boundInvoiceXpressDraft();
    $status = 'draft';
    Http::fake(function (Request $request) use (&$status) {
        expect($request->method())->toBe('GET')
            ->and(parse_url($request->url(), PHP_URL_PATH))->toBe('/invoices/65.json');

        return Http::response(invoiceXpressIssueResponse($status));
    });
    $sync = app(InvoiceXpressDraftSync::class);

    expect($sync->refresh($draft->id)->state)->toBe(FiscalDocumentDraft::Remote);
    $this->assertDatabaseEmpty('fiscal_documents');

    $status = 'final';
    $promoted = $sync->refresh($draft->id);

    expect($promoted->state)->toBe(FiscalDocumentDraft::Promoted)
        ->and($promoted->fiscalDocument->total_amount)->toBe(36918)
        ->and($promoted->fiscalDocument->atcud)->toBe('ABCD1234-7')
        ->and($promoted->fiscalDocument->links->receivable_id)->toBe($this->charge->id)
        ->and($promoted->preview)->toBe($draft->preview)
        ->and($sync->refresh($draft->id)->fiscal_document_id)->toBe($promoted->fiscal_document_id)
        ->and(InvoiceXpressSnapshot::sole()->fiscal_document_id)->toBe($promoted->fiscal_document_id);
    $this->assertDatabaseCount('fiscal_documents', 1);
    $this->assertDatabaseEmpty('payments');
});

it('lets the manual importer link an existing matching record and keep its source history', function () {
    $draft = boundInvoiceXpressDraft(['remote_id' => null, 'state' => FiscalDocumentDraft::Local]);
    $import = app(InvoiceXpressImport::class);
    $invoice = invoiceXpressIssueResponse('final');
    $existing = $import->confirmInvoice($invoice);
    $source = InvoiceXpressSnapshot::sole();
    $draft->update(['remote_id' => 65, 'state' => FiscalDocumentDraft::Remote]);
    fakeInvoiceXpressDraftRead('final');

    $review = $import->reviewInvoice($invoice, 'pilot');
    expect($review['state'])->toBe('changed');
    $result = $import->confirmInvoice($review['invoice'], $review['current_hash'], 'pilot', $review['local_hash']);

    expect($result->id)->toBe($existing->id)
        ->and($draft->fresh()->fiscal_document_id)->toBe($existing->id)
        ->and($result->links->receivable_id)->toBe($this->charge->id)
        ->and(InvoiceXpressSnapshot::sole()->id)->toBe($source->id)
        ->and($import->reviewInvoice($invoice, 'pilot')['state'])->toBe('same');
    $this->assertDatabaseCount('fiscal_documents', 1);
});

it('recovers a draft through manual synchronization if the send response was lost before external issuance', function (string $status, string $expectedStatus) {
    $draft = boundInvoiceXpressDraft(['remote_id' => null, 'state' => FiscalDocumentDraft::Sending]);
    $invoice = invoiceXpressIssueResponse('final', ['status' => $status]);
    fakeInvoiceXpressDraftRead('final', ['status' => $status]);
    $import = app(InvoiceXpressImport::class);
    $review = $import->reviewInvoice($invoice, 'pilot');

    $document = $import->confirmInvoice($review['invoice'], expectedSubdomain: 'pilot');

    expect($draft->fresh())->toMatchArray([
        'remote_id' => 65, 'fiscal_document_id' => $document->id, 'state' => FiscalDocumentDraft::Promoted,
    ])->and($document->links->receivable_id)->toBe($this->charge->id)
        ->and($document->status->value)->toBe($expectedStatus);
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
})->with([
    'issued' => ['final', 'active'],
    'issued and canceled' => ['canceled', 'voided'],
]);

it('records a draft canceled after issuance as voided without settling the linked charge', function () {
    $draft = boundInvoiceXpressDraft();
    fakeInvoiceXpressDraftRead('final', ['status' => 'canceled']);
    $sync = app(InvoiceXpressDraftSync::class);

    $promoted = $sync->refresh($draft->id);
    $document = $promoted->fiscalDocument;

    expect($promoted->state)->toBe(FiscalDocumentDraft::Promoted)
        ->and($document->status->value)->toBe('voided')
        ->and($document->number)->toBe('PILOTO/7')
        ->and($document->atcud)->toBe('ABCD1234-7')
        ->and($document->total_amount)->toBe(36918)
        ->and($document->links->receivable_id)->toBe($this->charge->id)
        ->and($this->charge->fresh()->outstanding)->toBe(36918)
        ->and($sync->refresh($draft->id)->fiscal_document_id)->toBe($document->id)
        ->and(InvoiceXpressSnapshot::sole()->source['document']['status'])->toBe('voided');
    $this->assertDatabaseCount('fiscal_documents', 1);
    $this->assertDatabaseEmpty('payments');
    $this->assertDatabaseEmpty('payment_allocations');
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
});

it('requires the saved charge to match even when the issued draft was canceled', function () {
    $draft = boundInvoiceXpressDraft();
    $this->charge->update(['amount' => 36919]);
    fakeInvoiceXpressDraftRead('final', ['status' => 'canceled']);

    expect(fn () => app(InvoiceXpressDraftSync::class)->refresh($draft->id))
        ->toThrow(ValidationException::class, __('The fiscal document or its link needs review in Contiu.'));
    expect($draft->fresh()->state)->toBe(FiscalDocumentDraft::Remote);
    $this->assertDatabaseEmpty('fiscal_documents');
    $this->assertDatabaseEmpty('invoice_xpress_snapshots');
});

it('rejects changes in remote identity, source or fiscal amounts before importing', function (array $overrides, string $status) {
    $draft = boundInvoiceXpressDraft();
    fakeInvoiceXpressDraftRead('final', ['status' => $status, ...$overrides]);

    expect(fn () => app(InvoiceXpressDraftSync::class)->refresh($draft->id))->toThrow(ValidationException::class)
        ->and($draft->fresh()->fiscal_document_id)->toBeNull();
    $this->assertDatabaseEmpty('invoice_xpress_snapshots');
    $this->assertDatabaseEmpty('fiscal_documents');
})->with([
    'different identity' => [['id' => 66]],
    'different document type' => [['type' => 'InvoiceReceipt']],
    'different amount by one cent' => [['total' => '369.19']],
    'wrong series' => [['inverted_sequence_number' => 'OTHER/7']],
    'missing sequence identity' => [['sequence_id' => null]],
    'ATCUD for a different number' => [['atcud' => 'ABCD1234-8']],
    'changed document terms' => [['due_date' => '11/12/2026']],
    'changed draft reference' => [['reference' => 'another draft']],
    'changed line with the same total' => [['items' => [['name' => 'Edited line']]]],
    'changed customer' => [['client' => ['id' => 43, 'name' => 'Cliente Teste', 'fiscal_id' => '508025338']]],
])->with(['final', 'canceled']);

it('preserves local edits and the previous source when a record has already been imported', function () {
    $draft = boundInvoiceXpressDraft(['remote_id' => null]);
    $document = app(InvoiceXpressImport::class)->confirmInvoice(invoiceXpressIssueResponse('final'));
    $document->update(['party_name' => 'Edited locally']);
    $draft->update(['remote_id' => 65]);
    fakeInvoiceXpressDraftRead('final');

    expect(fn () => app(InvoiceXpressDraftSync::class)->refresh($draft->id))->toThrow(ValidationException::class)
        ->and($draft->fresh()->fiscal_document_id)->toBeNull()
        ->and($document->fresh()->party_name)->toBe('Edited locally');
    $this->assertDatabaseCount('invoice_xpress_snapshots', 1);
});

it('scopes draft lookup to the Contiu account and each read to the bound provider account', function () {
    $draft = boundInvoiceXpressDraft();
    $sync = app(InvoiceXpressDraftSync::class);
    Http::fake();

    CurrentAccount::run(Account::factory()->create(), fn () => expect(fn () => $sync->refresh($draft->id))
        ->toThrow(ModelNotFoundException::class));
    InvoiceXpressConfig::store('different', 'fake-key');
    expect(fn () => $sync->refresh($draft->id))->toThrow(InvoiceXpressException::class);
    Http::assertNothingSent();
});

it('requires provider evidence of the original invoice before linking an adjustment note', function (string $status, bool $related, string $expectedStatus) {
    $remoteOriginal = invoiceXpressIssueResponse('final')['invoice'];
    $original = app(InvoiceXpressImport::class)->confirmInvoice($remoteOriginal);
    $draft = boundInvoiceXpressDraft([
        'document_type' => 'credit_note', 'remote_id' => 71,
        'preview' => [
            'input' => ['kind' => 'credit_note', 'line' => 1, 'quantity' => 3, 'reason' => 'Correção'],
            'payload' => ['date' => '10/11/2026'],
            'original' => [
                'id' => $original->id, 'providerId' => 65, 'number' => 'PILOTO/7',
                'customer' => ['id' => 42, 'name' => 'Cliente Teste', 'nif' => '508025338'],
                'lines' => [[
                    'article' => ['name' => 'Consultoria', 'description' => 'Serviço', 'unit' => 'service', 'unitCents' => 10005],
                    'tax' => ['id' => 9, 'name' => 'IVA Contiu', 'basisPoints' => 2300],
                    'quantity' => 3,
                    'totals' => ['net' => 30015, 'tax' => 6903, 'total' => 36918],
                ]],
            ],
            'sequence' => ['id' => 21, 'name' => 'PILOTO'],
            'totals' => ['net' => 30015, 'tax' => 6903, 'total' => 36918],
        ],
    ]);
    $note = [
        ...$remoteOriginal, 'id' => 71, 'type' => 'CreditNote', 'sequence_id' => 21, 'status' => $status,
        'inverted_sequence_number' => 'PILOTO/8', 'atcud' => 'ABCD1234-8', 'observations' => 'Correção', 'reference' => 'Contiu draft '.$draft->send_key,
    ];
    Http::fake([
        '*/credit_notes/71.json?*' => Http::response(['credit_note' => $note]),
        '*/document/65/related_documents.json?*' => Http::response(['documents' => $related ? [
            ['id' => 71, 'type' => 'CreditNote', 'status' => $status],
        ] : []]),
    ]);
    $sync = app(InvoiceXpressDraftSync::class);

    if (! $related) {
        expect(fn () => $sync->refresh($draft->id))->toThrow(ValidationException::class)
            ->and($draft->fresh()->fiscal_document_id)->toBeNull();
        $this->assertDatabaseCount('fiscal_documents', 1);

        return;
    }

    $document = $sync->refresh($draft->id)->fiscalDocument;
    expect($document->links->related_fiscal_document_id)->toBe($original->id)
        ->and($document->status->value)->toBe($expectedStatus);
    $this->assertDatabaseCount('fiscal_documents', 2);
    $this->assertDatabaseEmpty('payments');
})->with([
    'issued with original evidence' => ['final', true, 'active'],
    'issued without original evidence' => ['final', false, 'active'],
    'canceled with original evidence' => ['canceled', true, 'voided'],
    'canceled without original evidence' => ['canceled', false, 'voided'],
]);

it('imports a verified invoice receipt without creating a payment or a standalone receipt', function () {
    $draft = boundInvoiceXpressDraft(['document_type' => 'invoice_receipt']);
    $preview = $draft->preview;
    $preview['documentType'] = 'invoice_receipt';
    $preview['invoice']['due_date'] = '10/11/2026';
    $preview['sequence']['id'] = 25;
    $draft->update(['preview' => $preview]);
    Http::fake(['*/invoice_receipts/65.json?*' => Http::response(invoiceXpressIssueReceiptResponse('final'))]);

    $document = app(InvoiceXpressDraftSync::class)->refresh($draft->id)->fiscalDocument;

    expect($document->type->value)->toBe('invoice_receipt')
        ->and($document->links->receivable_id)->toBe($this->charge->id);
    $this->assertDatabaseCount('fiscal_documents', 1);
    $this->assertDatabaseEmpty('payments');
});

it('leaves standalone receipts outside draft promotion', function () {
    $draft = boundInvoiceXpressDraft(['document_type' => 'receipt']);
    Http::fake();

    expect(fn () => app(InvoiceXpressDraftSync::class)->refresh($draft->id))->toThrow(ValidationException::class);
    Http::assertNothingSent();
    $this->assertDatabaseEmpty('fiscal_documents');
});

it('raises an immediate conflict if the still-open provider draft was edited', function () {
    $draft = boundInvoiceXpressDraft();
    fakeInvoiceXpressDraftRead('draft', ['reference' => 'Edited in InvoiceXpress']);

    expect(fn () => app(InvoiceXpressDraftSync::class)->refresh($draft->id))->toThrow(ValidationException::class)
        ->and($draft->fresh()->fiscal_document_id)->toBeNull()
        ->and($draft->fresh()->preview)->toBe($draft->preview);
    $this->assertDatabaseEmpty('fiscal_documents');
});

it('allows a blocked draft with a verified provider identity to recover after correction', function () {
    $draft = boundInvoiceXpressDraft(['state' => FiscalDocumentDraft::Blocked]);
    fakeInvoiceXpressDraftRead('draft');

    expect(app(InvoiceXpressDraftSync::class)->refresh($draft->id)->state)->toBe(FiscalDocumentDraft::Remote);
    $this->assertDatabaseEmpty('fiscal_documents');
});

it('preserves the imported fiscal identifier when the provider reports a different ATCUD for the same number', function () {
    $draft = boundInvoiceXpressDraft();
    $atcud = 'ABCD1234-7';
    Http::fake(['*/invoices/65.json?*' => function () use (&$atcud) {
        return Http::response(invoiceXpressIssueResponse('final', ['atcud' => $atcud]));
    }]);
    $sync = app(InvoiceXpressDraftSync::class);
    $document = $sync->refresh($draft->id)->fiscalDocument;

    $atcud = 'EFGH5678-7';
    expect(fn () => $sync->refresh($draft->id))->toThrow(ValidationException::class)
        ->and($document->fresh()->atcud)->toBe('ABCD1234-7');
    $this->assertDatabaseCount('invoice_xpress_snapshots', 1);
});

it('keeps manual cancellation corrections available after promotion and adds a source version', function () {
    $draft = boundInvoiceXpressDraft();
    fakeInvoiceXpressDraftRead('final');
    $document = app(InvoiceXpressDraftSync::class)->refresh($draft->id)->fiscalDocument;
    $first = InvoiceXpressSnapshot::sole();

    $import = app(InvoiceXpressImport::class);
    $review = $import->reviewInvoice(invoiceXpressIssueResponse('final', ['status' => 'canceled']), 'pilot');
    expect($review['state'])->toBe('changed');

    $corrected = $import->confirmInvoice($review['invoice'], $review['current_hash'], 'pilot', $review['local_hash']);

    expect($corrected->id)->toBe($document->id)
        ->and($corrected->status->value)->toBe('voided')
        ->and($corrected->links->receivable_id)->toBe($this->charge->id)
        ->and($draft->fresh()->fiscal_document_id)->toBe($document->id)
        ->and($draft->fresh()->state)->toBe(FiscalDocumentDraft::Promoted)
        ->and($first->fresh()->source['document']['status'])->toBe('active');
    $this->assertDatabaseCount('invoice_xpress_snapshots', 2);
    Http::assertSentCount(1);
});

it('rejects a reviewed correction that would invalidate the promoted invoice link', function (array $overrides, int $localGross = 36918) {
    $draft = boundInvoiceXpressDraft();
    fakeInvoiceXpressDraftRead('final');
    $document = app(InvoiceXpressDraftSync::class)->refresh($draft->id)->fiscalDocument;
    if ($localGross !== $document->total_amount) {
        $document->update(['net_amount' => 30016, 'total_amount' => $localGross]);
    }

    $import = app(InvoiceXpressImport::class);
    $review = $import->reviewInvoice(invoiceXpressIssueResponse('final', $overrides), 'pilot');
    expect($review['state'])->toBe('changed');

    expect(fn () => $import->confirmInvoice($review['invoice'], $review['current_hash'], 'pilot', $review['local_hash']))
        ->toThrow(ValidationException::class, __('The receivable no longer matches the invoice. Look up the documents again.'));

    expect($document->fresh()->total_amount)->toBe($localGross)
        ->and($document->fresh()->party_tax_id)->toBe('508025338')
        ->and($document->fresh()->status->value)->toBe('active')
        ->and($document->links()->sole()->receivable_id)->toBe($this->charge->id);
    $this->assertDatabaseCount('invoice_xpress_snapshots', 1);
    Http::assertSentCount(1);
})->with([
    'different gross' => [['before_taxes' => '300.16', 'total' => '369.19']],
    'new official gross already entered locally' => [['before_taxes' => '300.16', 'total' => '369.19'], 36919],
    'different tax identity' => [['client' => ['name' => 'Outro cliente', 'country' => 'PT', 'fiscal_id' => '501234560']]],
    'cancellation with different gross' => [['status' => 'canceled', 'before_taxes' => '300.16', 'total' => '369.19']],
]);

it('applies a reviewed correction when the linked charge matches the corrected gross', function () {
    $draft = boundInvoiceXpressDraft();
    fakeInvoiceXpressDraftRead('final');
    $document = app(InvoiceXpressDraftSync::class)->refresh($draft->id)->fiscalDocument;
    $import = app(InvoiceXpressImport::class);
    $review = $import->reviewInvoice(invoiceXpressIssueResponse('final', ['before_taxes' => '300.16', 'total' => '369.19']), 'pilot');
    $this->charge->update(['amount' => 36919]);

    $corrected = $import->confirmInvoice($review['invoice'], $review['current_hash'], 'pilot', $review['local_hash']);

    expect($corrected->id)->toBe($document->id)
        ->and($corrected->total_amount)->toBe(36919)
        ->and($corrected->links->receivable_id)->toBe($this->charge->id);
    $this->assertDatabaseCount('invoice_xpress_snapshots', 2);
});
