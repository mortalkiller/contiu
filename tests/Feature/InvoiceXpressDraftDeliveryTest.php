<?php

use App\Exceptions\InvoiceXpressException;
use App\Models\Account;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentDraft;
use App\Models\InvoiceXpressConfig;
use App\Models\Setting;
use App\Support\CurrentAccount;
use App\Support\InvoiceXpressDraftDelivery;
use App\Support\InvoiceXpressImport;
use App\Support\InvoiceXpressIssue;
use App\Support\InvoiceXpressNoteIssue;
use App\Support\InvoiceXpressSandboxDrafts;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

pest()->group('invoicexpress', 'integration');

function enableInvoiceXpressDraftDelivery(): void
{
    config(['services.invoicexpress.sandbox_draft_account' => 'pilot']);
    $setting = Setting::query()->where('key', InvoiceXpressIssue::SettingsKey)->sole();
    $setting->update(['value' => [...$setting->value, 'draft_only' => true]]);
}

function invoiceXpressDeliveryFake(Closure $document, ?Closure $item = null, ?Closure $catalogTaxes = null): void
{
    Http::preventStrayRequests()->fake(function (Request $request) use ($document, $item, $catalogTaxes) {
        return match ([$request->method(), parse_url($request->url(), PHP_URL_PATH)]) {
            ['GET', '/clients/42.json'] => Http::response(['client' => invoiceXpressIssueResponse()['invoice']['client']]),
            ['GET', '/items/8.json'] => Http::response(['item' => [
                'id' => 8, ...invoiceXpressIssueResponse()['invoice']['items'][0], ...($item === null ? [] : $item()),
            ]]),
            ['GET', '/taxes.json'] => Http::response(['taxes' => $catalogTaxes === null
                ? [['id' => 9, 'name' => 'IVA Contiu', 'region' => 'PT', 'value' => 23]]
                : $catalogTaxes()]),
            ['GET', '/sequences/19.json'] => Http::response(['sequence' => [
                'id' => 19, 'serie' => InvoiceXpressSandboxDrafts::Series, 'current_invoice_sequence_id' => 19,
                'current_invoice_receipt_sequence_id' => 25,
                'current_credit_note_sequence_id' => 21, 'current_debit_note_sequence_id' => 22,
            ]]),
            default => $document($request),
        };
    });
}

function invoiceXpressDeliveryInput(int $receivableId): array
{
    return ['receivableId' => $receivableId, 'customerId' => 42, 'itemId' => 8, 'quantity' => 3];
}

it('returns the same draft when the first save response is lost', function () {
    $input = invoiceXpressDeliveryInput(invoiceXpressIssueReceivable()->id);
    enableInvoiceXpressDraftDelivery();
    invoiceXpressDeliveryFake(fn () => throw new LogicException('Unexpected request.'));
    $delivery = app(InvoiceXpressDraftDelivery::class);
    $revision = app(InvoiceXpressIssue::class)->preview(...$input)['revision'];
    $requestKey = (string) Str::uuid();

    $first = $delivery->saveInvoice($input, $revision, requestKey: $requestKey);
    $repeated = $delivery->saveInvoice($input, $revision, requestKey: $requestKey);

    expect($repeated->is($first))->toBeTrue()
        ->and($repeated->send_key)->toBe($requestKey);
    $this->assertDatabaseCount('fiscal_document_drafts', 1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
});

it('saves and edits a local invoice only with the recomputed preview revision', function () {
    $receivable = invoiceXpressIssueReceivable();
    enableInvoiceXpressDraftDelivery();
    invoiceXpressDeliveryFake(fn () => throw new LogicException('Unexpected request.'));
    $issue = app(InvoiceXpressIssue::class);
    $delivery = app(InvoiceXpressDraftDelivery::class);
    $input = invoiceXpressDeliveryInput($receivable->id);
    $preview = $issue->preview(...$input);

    $draft = $delivery->saveInvoice($input, $preview['revision']);

    expect($draft->fresh())->toMatchArray([
        'account_id' => $this->account->id, 'provider' => 'invoicexpress', 'provider_account' => 'pilot',
        'document_type' => 'invoice', 'state' => FiscalDocumentDraft::Local, 'remote_id' => null,
    ])->and($draft->preview['totals'])->toBe(['net' => 30015, 'tax' => 6903, 'total' => 36918])
        ->and($draft->payload['document']['items'][0]['unit_price_cents'])->toBe(10005);

    $changed = [...$input, 'documentType' => 'invoice_receipt'];
    expect(fn () => $delivery->saveInvoice($changed, $preview['revision'], $draft->id))->toThrow(ValidationException::class);

    $updated = $delivery->saveInvoice($changed, $issue->preview(...$changed)['revision'], $draft->id);

    expect($updated->id)->toBe($draft->id)
        ->and($updated->send_key)->toBe($draft->send_key)
        ->and($updated->document_type)->toBe('invoice_receipt')
        ->and($updated->payload['inputs'])->toBe($changed)
        ->and($updated->revision)->not->toBe($draft->revision);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
});

it('replays the persisted body and UUID after an unknown result and verifies the current draft', function () {
    $input = invoiceXpressDeliveryInput(invoiceXpressIssueReceivable()->id);
    enableInvoiceXpressDraftDelivery();
    $posts = [];
    $remote = null;
    invoiceXpressDeliveryFake(function (Request $request) use (&$posts, &$remote) {
        return match ([$request->method(), parse_url($request->url(), PHP_URL_PATH)]) {
            ['POST', '/invoices.json'] => (function () use ($request, &$posts, &$remote) {
                $draft = FiscalDocumentDraft::query()->sole();
                expect($draft->state)->toBe(FiscalDocumentDraft::Sending)
                    ->and($draft->send_key)->toBe($request['proprietary_uid']);
                $posts[] = $request->body();
                $remote = invoiceXpressIssueResponse(overrides: ['reference' => $request['invoice']['reference']]);

                return count($posts) === 1 ? Http::response([], 503) : Http::response($remote, 201);
            })(),
            ['GET', '/invoices/65.json'] => Http::response($remote),
            default => throw new LogicException('Unexpected request.'),
        };
    });
    $issue = app(InvoiceXpressIssue::class);
    $delivery = app(InvoiceXpressDraftDelivery::class);
    $draft = $delivery->saveInvoice($input, $issue->preview(...$input)['revision']);

    expect(fn () => $delivery->send($draft->id))->toThrow(InvoiceXpressException::class)
        ->and($draft->fresh()->state)->toBe(FiscalDocumentDraft::Sending)
        ->and($draft->fresh()->remote_id)->toBeNull()
        ->and(fn () => $delivery->saveInvoice($input, $draft->revision, $draft->id))->toThrow(ValidationException::class);

    $sent = $delivery->send($draft->id);
    expect($sent->state)->toBe(FiscalDocumentDraft::Remote)
        ->and($sent->remote_id)->toBe(65)
        ->and($sent->payload['reference'])->toBe('Contiu draft '.$draft->send_key)
        ->and($delivery->send($draft->id)->remote_id)->toBe(65)
        ->and(fn () => $delivery->saveInvoice($input, $draft->revision, $draft->id))->toThrow(ValidationException::class)
        ->and($posts)->toHaveCount(2)
        ->and($posts[1])->toBe($posts[0])
        ->and(json_decode($posts[0], true)['invoice']['items'][0]['unit_price'])->toBe(100.05);

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    $this->assertDatabaseEmpty('fiscal_documents');
});

it('rejects sending across Contiu accounts or after the InvoiceXpress account changes', function () {
    $input = invoiceXpressDeliveryInput(invoiceXpressIssueReceivable()->id);
    enableInvoiceXpressDraftDelivery();
    invoiceXpressDeliveryFake(fn () => throw new LogicException('Unexpected request.'));
    $delivery = app(InvoiceXpressDraftDelivery::class);
    $draft = $delivery->saveInvoice($input, app(InvoiceXpressIssue::class)->preview(...$input)['revision']);

    $other = Account::factory()->create();
    $other->users()->attach(auth()->id(), ['role' => 'owner']);
    CurrentAccount::run($other, function () use ($delivery, $draft): void {
        expect(fn () => $delivery->send($draft->id))->toThrow(ModelNotFoundException::class);
    });

    InvoiceXpressConfig::store('another-pilot', 'fake-test-credential');
    expect(fn () => $delivery->send($draft->id))->toThrow(HttpException::class)
        ->and($draft->fresh()->state)->toBe(FiscalDocumentDraft::Local);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
});

it('keeps the local draft editable when the provider preview changes before sending', function () {
    $input = invoiceXpressDeliveryInput(invoiceXpressIssueReceivable()->id);
    enableInvoiceXpressDraftDelivery();
    $price = '100.05';
    invoiceXpressDeliveryFake(fn () => throw new LogicException('Unexpected request.'), function () use (&$price): array {
        return ['unit_price' => $price];
    });
    $delivery = app(InvoiceXpressDraftDelivery::class);
    $draft = $delivery->saveInvoice($input, app(InvoiceXpressIssue::class)->preview(...$input)['revision']);
    $price = '100.06';

    expect(fn () => $delivery->send($draft->id))->toThrow(ValidationException::class)
        ->and($draft->fresh()->state)->toBe(FiscalDocumentDraft::Local);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
});

it('refuses to link an invoice that was modified after creation', function (array $overrides) {
    $input = invoiceXpressDeliveryInput(invoiceXpressIssueReceivable()->id);
    enableInvoiceXpressDraftDelivery();
    $remote = null;
    invoiceXpressDeliveryFake(function (Request $request) use (&$remote, $overrides) {
        return match ([$request->method(), parse_url($request->url(), PHP_URL_PATH)]) {
            ['POST', '/invoices.json'] => Http::response($remote = invoiceXpressIssueResponse(overrides: ['reference' => $request['invoice']['reference']]), 201),
            ['GET', '/invoices/65.json'] => Http::response(['invoice' => [...$remote['invoice'], ...$overrides]]),
            default => throw new LogicException('Unexpected request.'),
        };
    });
    $delivery = app(InvoiceXpressDraftDelivery::class);
    $draft = $delivery->saveInvoice($input, app(InvoiceXpressIssue::class)->preview(...$input)['revision']);

    expect(fn () => $delivery->send($draft->id))->toThrow(InvoiceXpressException::class)
        ->and($draft->fresh()->state)->toBe(FiscalDocumentDraft::Sending)
        ->and($draft->fresh()->remote_id)->toBeNull();
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
})->with([
    'wrong ID' => [['id' => 66]],
    'final state' => [['status' => 'final']],
    'edited reference' => [['reference' => 'Edited']],
    'edited amount' => [['total' => '369.19']],
    'archived' => [['archived' => true]],
]);

it('saves and sends notes against the original provider invoice', function (string $kind, string $type, int $sequence, string $sourceEvidence) {
    invoiceXpressIssueReceivable();
    enableInvoiceXpressDraftDelivery();
    $source = [...invoiceXpressIssueResponse('final')['invoice'], 'due_date' => '10/11/2026'];
    $original = app(InvoiceXpressImport::class)->confirmInvoice($source);
    $input = ['originalId' => $original->id, 'kind' => $kind, 'line' => 1, 'itemId' => 8, 'quantity' => 3, 'reason' => 'Corrigir serviço'];
    $remote = null;
    invoiceXpressDeliveryFake(function (Request $request) use ($source, $kind, $type, $sequence, $sourceEvidence, &$remote) {
        return match ([$request->method(), parse_url($request->url(), PHP_URL_PATH)]) {
            ['GET', '/invoices/65.json'] => Http::response(['invoice' => $source]),
            ['GET', '/document/65/related_documents.json'] => Http::response([
                'documents' => $sourceEvidence === 'related' && $remote !== null ? [$remote[$kind]] : [],
            ]),
            ['POST', "/{$kind}s.json"] => Http::response($remote = [$kind => [
                ...$source, 'id' => 71, 'type' => $type, 'status' => 'draft', 'sequence_id' => $sequence,
                'observations' => $request[$kind]['observations'], 'reference' => $request[$kind]['reference'],
                'owner_invoice_id' => $request[$kind]['owner_invoice_id'],
            ]], 201),
            ['GET', "/{$kind}s/71.json"] => Http::response([$kind => match ($sourceEvidence) {
                'wrong' => [...$remote[$kind], 'owner_invoice_id' => 999],
                'related' => array_diff_key($remote[$kind], array_flip(['owner_invoice_id', 'sequence_id'])),
                default => $remote[$kind],
            }]),
            default => throw new LogicException('Unexpected request.'),
        };
    });
    $delivery = app(InvoiceXpressDraftDelivery::class);
    $preview = app(InvoiceXpressNoteIssue::class)->preview($input);
    $draft = $delivery->saveNote($input, $preview['revision']);

    expect($draft->document_type)->toBe($kind)
        ->and($draft->preview['original']['providerId'])->toBe(65)
        ->and($draft->payload['document']['owner_invoice_id'])->toBe(65);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');

    if ($sourceEvidence === 'wrong') {
        expect(fn () => $delivery->send($draft->id))->toThrow(InvoiceXpressException::class)
            ->and($draft->fresh()->remote_id)->toBeNull();
    } else {
        expect($delivery->send($draft->id)->remote_id)->toBe(71);
    }

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    expect(FiscalDocument::query()->count())->toBe(1);
})->with([
    'credit note' => ['credit_note', 'CreditNote', 21, 'owner'],
    'debit note' => ['debit_note', 'DebitNote', 22, 'owner'],
    'related draft without sequence or owner fields' => ['credit_note', 'CreditNote', 21, 'related'],
    'wrong source' => ['credit_note', 'CreditNote', 21, 'wrong'],
]);

it('blocks a sending note replay when the item or catalog tax no longer matches the original regional line', function (string $changedTax, string $errorKey) {
    invoiceXpressIssueReceivable();
    enableInvoiceXpressDraftDelivery();
    $source = invoiceXpressIssueResponse('final', ['due_date' => '10/11/2026', 'taxes' => 12.01, 'total' => 312.16])['invoice'];
    $tax = ['id' => 14, 'name' => 'IVA Açores 4', 'region' => 'PT-AC', 'code' => 'RED', 'value' => 4];
    $source['items'][0] = [...$source['items'][0], 'tax_amount' => 12.01, 'total' => 312.16, 'tax' => $tax];
    $original = app(InvoiceXpressImport::class)->confirmInvoice($source);
    $input = ['originalId' => $original->id, 'kind' => 'credit_note', 'line' => 1, 'itemId' => 8, 'quantity' => 3, 'reason' => 'Corrigir serviço'];
    $taxes = ['item' => $tax, 'catalog' => $tax];
    invoiceXpressDeliveryFake(
        fn (Request $request) => match ([$request->method(), parse_url($request->url(), PHP_URL_PATH)]) {
            ['GET', '/invoices/65.json'] => Http::response(['invoice' => $source]),
            ['GET', '/document/65/related_documents.json'] => Http::response(['documents' => []]),
            ['POST', '/credit_notes.json'] => Http::response([], 503),
            default => throw new LogicException('Unexpected request.'),
        },
        function () use (&$taxes): array {
            return ['tax' => $taxes['item']];
        },
        function () use (&$taxes): array {
            return [$taxes['catalog']];
        },
    );
    $delivery = app(InvoiceXpressDraftDelivery::class);
    $preview = app(InvoiceXpressNoteIssue::class)->preview($input);
    $draft = $delivery->saveNote($input, $preview['revision']);

    expect(fn () => $delivery->send($draft->id))->toThrow(InvoiceXpressException::class)
        ->and($draft->fresh()->state)->toBe(FiscalDocumentDraft::Sending);

    $taxes[$changedTax]['region'] = 'PT-MA';
    expect(fn () => $delivery->send($draft->id))
        ->toThrow(fn (ValidationException $error) => expect($error->errors())->toHaveKey($errorKey))
        ->and($draft->fresh()->state)->toBe(FiscalDocumentDraft::Sending);
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(1);
})->with([
    'item tax' => ['item', 'confirmation'],
    'catalog tax' => ['catalog', 'tax'],
]);

it('saves a verified registered-series invoice locally and blocks its outbound delivery', function () {
    $input = invoiceXpressDeliveryInput(invoiceXpressIssueReceivable()->id);
    invoiceXpressIssueFake(fn () => throw new LogicException('Unexpected request.'));
    $delivery = app(InvoiceXpressDraftDelivery::class);
    $draft = $delivery->saveInvoice($input, app(InvoiceXpressIssue::class)->preview(...$input)['revision']);

    expect($draft->state)->toBe(FiscalDocumentDraft::Local)
        ->and($draft->preview['sequence']['name'])->toBe('PILOTO')
        ->and(fn () => $delivery->send($draft->id))->toThrow(HttpException::class)
        ->and($draft->fresh()->state)->toBe(FiscalDocumentDraft::Local);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
});

it('blocks definitive issuance for the designated sandbox even when the catalog is allowlisted', function () {
    invoiceXpressIssueReceivable();
    config(['services.invoicexpress.sandbox_draft_account' => 'pilot']);
    Http::preventStrayRequests()->fake();

    expect(InvoiceXpressIssue::canWriteDocuments())->toBeFalse();
    Http::assertNothingSent();
});

it('leaves a pending delivery unlinked when a replay returns a manually finalized document', function () {
    $input = invoiceXpressDeliveryInput(invoiceXpressIssueReceivable()->id);
    enableInvoiceXpressDraftDelivery();
    $posts = [];
    invoiceXpressDeliveryFake(function (Request $request) use (&$posts) {
        if ($request->method() !== 'POST' || parse_url($request->url(), PHP_URL_PATH) !== '/invoices.json') {
            throw new LogicException('Unexpected request.');
        }

        $posts[] = $request->body();

        return count($posts) === 1 ? Http::response([], 503) : Http::response(
            invoiceXpressIssueResponse('final', ['reference' => $request['invoice']['reference']]),
            201,
        );
    });
    $delivery = app(InvoiceXpressDraftDelivery::class);
    $draft = $delivery->saveInvoice($input, app(InvoiceXpressIssue::class)->preview(...$input)['revision']);

    expect(fn () => $delivery->send($draft->id))->toThrow(InvoiceXpressException::class)
        ->and(fn () => $delivery->send($draft->id))->toThrow(InvoiceXpressException::class)
        ->and($draft->fresh()->state)->toBe(FiscalDocumentDraft::Sending)
        ->and($draft->fresh()->remote_id)->toBeNull()
        ->and($posts)->toHaveCount(2)
        ->and($posts[0])->toBe($posts[1]);

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    $this->assertDatabaseEmpty('fiscal_documents');
});

it('detects a catalog mutation after sending before linking the draft', function () {
    $input = invoiceXpressDeliveryInput(invoiceXpressIssueReceivable()->id);
    enableInvoiceXpressDraftDelivery();
    $price = '100.05';
    invoiceXpressDeliveryFake(function (Request $request) use (&$price) {
        if ($request->method() !== 'POST' || parse_url($request->url(), PHP_URL_PATH) !== '/invoices.json') {
            throw new LogicException('Unexpected request.');
        }

        $price = '100.06';

        return Http::response(invoiceXpressIssueResponse(overrides: ['reference' => $request['invoice']['reference']]), 201);
    }, function () use (&$price): array {
        return ['unit_price' => $price];
    });
    $delivery = app(InvoiceXpressDraftDelivery::class);
    $draft = $delivery->saveInvoice($input, app(InvoiceXpressIssue::class)->preview(...$input)['revision']);

    expect(fn () => $delivery->send($draft->id))->toThrow(InvoiceXpressException::class)
        ->and($draft->fresh()->state)->toBe(FiscalDocumentDraft::Sending)
        ->and($draft->fresh()->remote_id)->toBeNull()
        ->and(fn () => $delivery->send($draft->id))->toThrow(ValidationException::class)
        ->and(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(1);
});
