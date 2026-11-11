<?php

use App\Models\FiscalDocument;
use App\Models\InvoiceXpressConfig;
use App\Models\InvoiceXpressIssueAttempt;
use App\Models\InvoiceXpressNoteAttempt;
use App\Models\Setting;
use App\Support\InvoiceXpressCancellation;
use App\Support\InvoiceXpressImport;
use App\Support\InvoiceXpressIssue;
use App\Support\InvoiceXpressNoteIssue;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

pest()->group('invoicexpress');

function ixRegionalHistoryLine(int $id, string $name, int $rate, array $taxDetails = []): array
{
    return [
        'name' => 'Consultoria', 'description' => 'Serviço', 'unit' => 'service', 'unit_price' => '100.00',
        'quantity' => 1, 'subtotal' => 100, 'tax_amount' => $rate, 'total' => 100 + $rate,
        'tax' => ['id' => $id, 'name' => $name, 'value' => $rate, ...$taxDetails],
    ];
}

function ixRegionalHistoryInvoice(array $items): array
{
    return [
        'id' => 65, 'type' => 'Invoice', 'status' => 'final', 'currency' => 'Euro',
        'date' => '10/11/2026', 'inverted_sequence_number' => 'PILOTO/7',
        'before_taxes' => array_sum(array_column($items, 'subtotal')),
        'taxes' => array_sum(array_column($items, 'tax_amount')),
        'total' => array_sum(array_column($items, 'total')),
        'client' => ['id' => 42, 'name' => 'Cliente Teste', 'country' => 'Portugal', 'fiscal_id' => '508025338'],
        'items' => $items,
    ];
}

it('keeps historic line rates and amounts and records a region only when supplied on that line', function () {
    actingAsAdmin();
    InvoiceXpressConfig::store('pilot', 'fake-test-credential');
    Http::preventStrayRequests();
    $invoice = ixRegionalHistoryInvoice([
        ixRegionalHistoryLine(4, 'IVA Açores 4', 4, ['region' => 'PT-AC', 'code' => 'RED']),
        ixRegionalHistoryLine(5, 'IVA Madeira 4', 4),
        ixRegionalHistoryLine(6, 'IVA anterior', 5, ['region' => 'PT-MA', 'code' => 'RED']),
    ]);
    $import = app(InvoiceXpressImport::class);

    $review = $import->reviewInvoice($invoice);
    $document = $import->confirmInvoice($review['invoice']);
    $summary = $invoice;
    unset($summary['items']);

    $lines = $document->invoiceXpressSnapshot->source['tax_lines'] ?? [];
    expect($lines)->toHaveCount(3)
        ->and($lines[0]['tax'])->toMatchArray(['region' => 'PT-AC', 'code' => 'RED', 'value' => '4'])
        ->and($lines[1]['tax'])->not->toHaveKey('region')
        ->and($lines[2]['tax'])->toMatchArray(['region' => 'PT-MA', 'code' => 'RED', 'value' => '5'])
        ->and($lines[2]['tax_amount'])->toBe('5')
        ->and($document->vat_amount)->toBe(1300)
        ->and($import->reviewInvoice($summary)['state'])->toBe('same');
});

it('adds verified regional tax evidence when recovering an intact import', function (bool $hasLines) {
    actingAsAdmin();
    InvoiceXpressConfig::store('pilot', 'fake-test-credential');
    Http::preventStrayRequests();
    $invoice = ixRegionalHistoryInvoice([ixRegionalHistoryLine(4, 'IVA 4', 4)]);
    $import = app(InvoiceXpressImport::class);
    $original = $import->confirmInvoice($hasLines ? $invoice : array_diff_key($invoice, ['items' => true]));
    $verified = ['id' => 4, 'name' => 'IVA 4', 'basisPoints' => 400, 'region' => 'PT-AC'];

    $record = $import->confirmInvoice($invoice, verifiedLineTax: $verified);
    $replayed = $import->confirmInvoice($invoice, verifiedLineTax: $verified);

    expect($record->id)->toBe($original->id)
        ->and($record->invoiceXpressSnapshot->source['tax_lines'][0]['tax']['region'])->toBe('PT-AC')
        ->and($replayed->invoiceXpressSnapshot->id)->toBe($record->invoiceXpressSnapshot->id)
        ->and($import->reviewInvoice($invoice)['state'])->toBe('same')
        ->and(fn () => $import->confirmInvoice($invoice, verifiedLineTax: [...$verified, 'region' => 'PT-MA']))
        ->toThrow(ValidationException::class);
})->with(['import with lines' => true, 'legacy import without lines' => false]);

it('recovers an issued invoice that was imported before the official line details were available', function () {
    $receivable = invoiceXpressIssueReceivable();
    $finalized = false;
    invoiceXpressIssueFake(function (Request $request) use (&$finalized) {
        if ($request->method() === 'POST') {
            return Http::response(['invoice' => ['id' => 65]], 201);
        }

        if ($request->method() === 'PUT') {
            $finalized = true;

            throw new ConnectionException('Fake finalization timeout');
        }

        return Http::response(invoiceXpressIssueResponse($finalized ? 'final' : 'draft'));
    });
    $issue = app(InvoiceXpressIssue::class);
    $preview = $issue->preview($receivable->id, 42, 8, 3);
    $attempt = $issue->confirm($receivable->id, 42, 8, 3, $preview['revision']);
    expect($attempt->state)->toBe(InvoiceXpressIssueAttempt::Draft);
    $summary = array_diff_key(invoiceXpressIssueResponse('final')['invoice'], ['items' => true]);
    $imported = app(InvoiceXpressImport::class)->confirmInvoice($summary);

    $recovered = $issue->recover($attempt->id);

    expect($recovered->state)->toBe(InvoiceXpressIssueAttempt::Finalized)
        ->and($recovered->fiscal_document_id)->toBe($imported->id)
        ->and($imported->fresh()->invoiceXpressSnapshot->source['tax_lines'][0]['tax']['value'])->toBe('23');
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(1);
});

it('checks line evidence on both sides of the cancellation request', function (string $readBack) {
    actingAsAdmin();
    InvoiceXpressConfig::store('pilot', 'fake-test-credential');
    config(['services.invoicexpress.catalog_safe_accounts' => ['pilot']]);
    $invoice = ixRegionalHistoryInvoice([ixRegionalHistoryLine(4, 'IVA Açores 4', 4, ['region' => 'PT-AC', 'code' => 'RED'])]);
    $import = app(InvoiceXpressImport::class);
    $original = $import->confirmInvoice($readBack === 'legacy' ? array_diff_key($invoice, ['items' => true]) : $invoice);
    $sourceHash = $original->invoiceXpressSnapshot->source_hash;
    $status = 'final';
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($invoice, &$status, $readBack) {
        if ($status === 'canceled') {
            $invoice['items'][0]['tax'] = match ($readBack) {
                'conflicting' => ['id' => 4, 'region' => 'PT-MA'],
                'partial' => ['value' => 4],
                default => $invoice['items'][0]['tax'],
            };
        }

        return match ([$request->method(), parse_url($request->url(), PHP_URL_PATH)]) {
            ['GET', '/invoices/65.json'] => Http::response(['invoice' => [...$invoice, 'status' => $status]]),
            ['GET', '/document/65/related_documents.json'] => Http::response(['documents' => []]),
            ['PUT', '/invoices/65/change-state.json'] => tap(Http::response('', 204), function () use (&$status): void {
                $status = 'canceled';
            }),
            default => throw new LogicException('Unexpected InvoiceXpress request'),
        };
    });

    $cancel = fn () => app(InvoiceXpressCancellation::class)->cancel($original->id, 'Duplicado', $sourceHash);

    if ($readBack === 'conflicting') {
        expect($cancel)->toThrow(ValidationException::class);
        $document = $original->fresh();
        expect($document->status->value)->toBe('active')
            ->and($document->invoiceXpressSnapshot->source_hash)->toBe($sourceHash);
    } else {
        $document = $cancel();
        expect($document->status->value)->toBe('voided');
    }

    expect($document->invoiceXpressSnapshot->source['tax_lines'][0]['tax']['region'])->toBe('PT-AC');
})->with(['legacy enrichment' => 'legacy', 'conflicting read-back' => 'conflicting', 'partial read-back' => 'partial']);

it('preserves a safely matched partial line and detects conflicting tax evidence', function () {
    actingAsAdmin();
    InvoiceXpressConfig::store('pilot', 'fake-test-credential');
    Http::preventStrayRequests();
    $invoice = ixRegionalHistoryInvoice([
        ixRegionalHistoryLine(4, 'IVA Açores 4', 4, ['region' => 'PT-AC', 'code' => 'RED']),
        ixRegionalHistoryLine(5, 'IVA Madeira 4', 4, ['region' => 'PT-MA', 'code' => 'RED']),
    ]);
    $import = app(InvoiceXpressImport::class);
    $document = $import->confirmInvoice($invoice);
    $sourceHash = $document->invoiceXpressSnapshot->source_hash;
    $partial = $invoice;
    $partial['items'][0]['tax'] = ['id' => 4];
    $partial['items'][1]['tax'] = ['name' => 'IVA Madeira 4', 'value' => 4];

    $review = $import->reviewInvoice($partial);

    expect($review['state'])->toBe('same')
        ->and($review['source_hash'])->toBe($sourceHash);
    $partial['items'][0]['tax']['region'] = 'PT-MA';
    expect($import->reviewInvoice($partial)['state'])->toBe('changed')
        ->and(fn () => $import->confirmInvoice($partial))->toThrow(ValidationException::class);
    expect($document->fresh()->invoiceXpressSnapshot->source_hash)->toBe($sourceHash);
});

it('uses the original regional tax when issuing a correction', function (string $region, string $kind, string $type, bool $wrongRegion, bool $legacyImport = false) {
    $owner = actingAsAdmin();
    $this->travelTo(now()->setDate(2026, 11, 10));
    InvoiceXpressConfig::store('pilot', 'fake-test-credential');
    Setting::create(['key' => 'invoice_xpress_issue', 'value' => [
        'provider' => 'invoicexpress', 'user_id' => $owner->id, 'subdomain' => 'pilot',
        'item_ids' => [8], 'tax_name' => 'IVA23', 'sequence_id' => 19,
    ]]);
    config(['services.invoicexpress.catalog_safe_accounts' => ['pilot']]);
    $tax = ['id' => 4, 'name' => 'IVA Regional 4', 'value' => 4, 'region' => $region, 'code' => 'RED'];
    $line = [...ixRegionalHistoryLine(4, $tax['name'], 4), 'tax' => $tax];
    $invoice = ixRegionalHistoryInvoice([$line, ixRegionalHistoryLine(6, 'Outro imposto 4 sem região', 4)]);
    $document = app(InvoiceXpressImport::class)->confirmInvoice($invoice);
    $readBack = $invoice;
    unset($readBack['items'][0]['tax']['region']);

    $remote = [
        ...ixRegionalHistoryInvoice([$readBack['items'][0]]), 'id' => 71, 'type' => $type, 'status' => 'draft', 'owner_invoice_id' => 65,
        'observations' => 'Corrigir serviço', 'inverted_sequence_number' => 'PILOTO/1',
        'sequence_id' => $kind === 'credit_note' ? 21 : 22,
        'atcud' => ($kind === 'credit_note' ? 'CREDIT01' : 'DEBIT001').'-1',
    ];
    $otherRegion = $region === 'PT-AC' ? 'PT-MA' : 'PT-AC';
    if ($wrongRegion) {
        $remote['items'][0]['tax']['region'] = $otherRegion;
    }
    $existingNote = $legacyImport
        ? app(InvoiceXpressImport::class)->confirmInvoice([...array_diff_key($remote, ['items' => true]), 'status' => 'final'])
        : null;
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($readBack, $line, $tax, $otherRegion, $kind, &$remote) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        switch ([$request->method(), $path]) {
            case ['POST', "/{$kind}s.json"]:
                $remote['reference'] = $request[$kind]['reference'];

                return Http::response([$kind => ['id' => 71]], 201);
            case ['GET', "/{$kind}s/71.json"]:
                return Http::response([$kind => $remote]);
            case ['PUT', "/{$kind}s/71/change-state.json"]:
                $remote['status'] = 'final';

                return Http::response('', 204);
        }

        return match ($path) {
            '/invoices/65.json' => Http::response(['invoice' => $readBack]),
            '/clients/42.json' => Http::response(['client' => $readBack['client']]),
            '/items/8.json' => Http::response(['item' => ['id' => 8, ...$line]]),
            '/taxes.json' => Http::response(['taxes' => [$tax, [...$tax, 'id' => 5, 'name' => 'Outro IVA 4', 'region' => $otherRegion]]]),
            '/sequences/19.json' => Http::response(['sequence' => [
                'id' => 19, 'serie' => 'PILOTO',
                'current_credit_note_sequence_id' => 21, 'current_credit_note_validation_code' => 'CREDIT01',
                'current_debit_note_sequence_id' => 22, 'current_debit_note_validation_code' => 'DEBIT001',
            ]]),
            '/document/65/related_documents.json' => Http::response(['documents' => []]),
            default => throw new LogicException('Unexpected InvoiceXpress request'),
        };
    });
    $input = ['originalId' => $document->id, 'kind' => $kind, 'line' => 1, 'itemId' => 8, 'quantity' => 1, 'reason' => 'Corrigir serviço'];

    $issue = app(InvoiceXpressNoteIssue::class);
    $preview = $issue->preview($input);
    $attempt = $issue->confirm($input, $preview['revision']);

    expect($preview['original']['lines'][0]['tax'])->toMatchArray([
        'id' => 4, 'name' => 'IVA Regional 4', 'basisPoints' => 400, 'region' => $region, 'code' => 'RED', 'category' => 'reduced',
    ])->and($preview['payload']['items'][0]['tax'])->toBe(['name' => 'IVA Regional 4'])
        ->and($preview['totals'])->toBe(['net' => 10000, 'tax' => 400, 'total' => 10400]);
    expect($attempt->state)->toBe($wrongRegion ? InvoiceXpressNoteAttempt::Blocked : InvoiceXpressNoteAttempt::Finalized);
    if ($wrongRegion) {
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    } else {
        $note = FiscalDocument::query()->findOrFail($attempt->fiscal_document_id);
        if ($existingNote !== null) {
            expect($note->id)->toBe($existingNote->id);
        }
        expect($note->invoiceXpressSnapshot->source['tax_lines'][0]['tax']['region'])->toBe($region)
            ->and($note->links->related_fiscal_document_id)->toBe($document->id);
    }
})->with([
    'Azores credit' => ['PT-AC', 'credit_note', 'CreditNote', false],
    'Madeira debit' => ['PT-MA', 'debit_note', 'DebitNote', false],
    'same rate in wrong region' => ['PT-MA', 'credit_note', 'CreditNote', true],
    'credit recovery after a legacy import' => ['PT-AC', 'credit_note', 'CreditNote', false, true],
]);

it('rejects a 4 percent correction without original line region evidence', function () {
    $owner = actingAsAdmin();
    $this->travelTo(now()->setDate(2026, 11, 10));
    InvoiceXpressConfig::store('pilot', 'fake-test-credential');
    Setting::create(['key' => 'invoice_xpress_issue', 'value' => [
        'provider' => 'invoicexpress', 'user_id' => $owner->id, 'subdomain' => 'pilot',
        'item_ids' => [8], 'tax_name' => 'IVA23', 'sequence_id' => 19,
    ]]);
    $invoice = ixRegionalHistoryInvoice([ixRegionalHistoryLine(4, 'IVA 4', 4, ['code' => 'RED'])]);
    $document = app(InvoiceXpressImport::class)->confirmInvoice($invoice);
    Http::preventStrayRequests();
    Http::fake(['*/invoices/65.json*' => Http::response(['invoice' => $invoice])]);

    expect(fn () => app(InvoiceXpressNoteIssue::class)->preview([
        'originalId' => $document->id, 'kind' => 'credit_note', 'line' => 1, 'itemId' => 8,
        'quantity' => 1, 'reason' => 'Corrigir serviço',
    ]))->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('line'));
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
});
