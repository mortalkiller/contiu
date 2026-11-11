<?php

use App\Filament\Pages\InvoiceXpressNoteIssuePage;
use App\Models\Account;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentDraft;
use App\Models\InvoiceXpressConfig;
use App\Models\InvoiceXpressNoteAttempt;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\Setting;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\InvoiceXpressImport;
use App\Support\InvoiceXpressNoteIssue;
use App\Support\InvoiceXpressNoteTerms;
use App\Support\InvoiceXpressSandboxDrafts;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

pest()->group('invoicexpress');

function ixNoteLine(int $quantity): array
{
    return [
        'name' => 'Consultoria', 'description' => 'Serviço', 'unit' => 'service', 'unit_price' => '100.00',
        'quantity' => $quantity, 'discount' => 0, 'subtotal' => 100 * $quantity, 'tax_amount' => 23 * $quantity,
        'total' => 123 * $quantity, 'tax' => ['id' => 9, 'name' => 'IVA23', 'value' => 23],
    ];
}

function ixNoteDocument(string $type = 'Invoice', string $status = 'final', int $quantity = 3): array
{
    $credit = $type === 'CreditNote';

    return [
        'id' => $type === 'Invoice' ? 65 : 71,
        'type' => $type, 'status' => $status, 'currency' => 'Euro',
        'date' => '10/11/2026', 'inverted_sequence_number' => $type === 'Invoice' ? 'PILOTO/7' : 'PILOTO/1',
        'sequence_id' => $credit ? 21 : 22, 'atcud' => ($credit ? 'CREDIT01' : 'DEBIT001').'-1',
        'before_taxes' => 100 * $quantity, 'taxes' => 23 * $quantity, 'total' => 123 * $quantity,
        'client' => ['id' => 42, 'name' => 'Cliente Teste', 'country' => 'Portugal', 'fiscal_id' => '508025338'],
        'items' => [ixNoteLine($quantity)],
        'owner_invoice_id' => 65, 'observations' => 'Corrigir serviço',
    ];
}

function ixDiscountedNoteDocument(string $type = 'Invoice', string $status = 'final'): array
{
    $invoice = $type === 'Invoice';
    $quantity = $invoice ? 3 : 1;
    [$net, $tax, $total, $discount] = $invoice ? ['0.21', '0.05', '0.26', '0.03'] : ['0.07', '0.02', '0.09', '0.01'];

    return [...ixNoteDocument($type, $status, $quantity), 'before_taxes' => $net, 'taxes' => $tax, 'total' => $total, 'items' => [[
        ...ixNoteLine($quantity), 'unit_price' => '0.08', 'discount' => '12.50', 'discount_amount' => $discount,
        'subtotal' => $net, 'tax_amount' => $tax, 'total' => $total,
    ]]];
}

function ixNoteOriginal(bool $allowWrites = true, array $overrides = []): FiscalDocument
{
    $owner = actingAsAdmin();
    test()->travelTo(now()->setDate(2026, 11, 10));
    config(['services.invoicexpress.catalog_safe_accounts' => $allowWrites ? ['pilot'] : []]);
    InvoiceXpressConfig::store('pilot', 'fake-test-credential');
    Setting::create(['key' => 'invoice_xpress_issue', 'value' => [
        'provider' => 'invoicexpress', 'user_id' => $owner->id, 'subdomain' => 'pilot',
        'item_ids' => [8], 'tax_name' => 'IVA23', 'sequence_id' => 19,
    ]]);

    return app(InvoiceXpressImport::class)->confirmInvoice([...ixNoteDocument(), ...$overrides]);
}

function ixNoteFake(Closure $documents, ?Closure $writes = null, array $originalOverrides = [], array $itemOverrides = [], array $sequenceOverrides = [], array $taxOverrides = []): void
{
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($documents, $writes, $originalOverrides, $itemOverrides, $sequenceOverrides, $taxOverrides) {
        return match (parse_url($request->url(), PHP_URL_PATH)) {
            '/invoices/65.json' => Http::response(['invoice' => array_replace(ixNoteDocument(), $originalOverrides)]),
            '/invoice_receipts/65.json' => Http::response(['invoice_receipt' => array_replace(ixNoteDocument(), $originalOverrides)]),
            '/clients/42.json' => Http::response(['client' => $originalOverrides['client'] ?? ixNoteDocument()['client']]),
            '/items/8.json' => Http::response(['item' => ['id' => 8, ...ixNoteLine(3), ...$itemOverrides]]),
            '/taxes.json' => Http::response(['taxes' => [[...($originalOverrides['items'][0]['tax'] ?? ixNoteLine(3)['tax']), 'region' => 'PT', 'code' => ($originalOverrides['items'][0]['tax']['value'] ?? 23) === 0 ? 'ISE' : 'NOR', ...$taxOverrides]]]),
            '/sequences/19.json' => Http::response(['sequence' => [
                'id' => 19, 'serie' => 'PILOTO',
                'current_credit_note_sequence_id' => 21, 'current_credit_note_validation_code' => 'CREDIT01',
                'current_debit_note_sequence_id' => 22, 'current_debit_note_validation_code' => 'DEBIT001',
                ...$sequenceOverrides,
            ]]),
            '/document/65/related_documents.json' => Http::response(['documents' => $documents()]),
            default => $writes === null ? throw new LogicException('Pedido inesperado: '.parse_url($request->url(), PHP_URL_PATH)) : $writes($request),
        };
    });
}

function ixNoteInput(FiscalDocument $original, string $kind = 'credit_note', int $quantity = 1): array
{
    return ['originalId' => $original->id, 'kind' => $kind, 'line' => 1, 'itemId' => 8, 'quantity' => $quantity, 'reason' => 'Corrigir serviço'];
}

it('previews exact amounts without writes and checks the catalogue guard on confirmation', function () {
    $original = ixNoteOriginal(false);
    ixNoteFake(fn () => []);
    $issue = app(InvoiceXpressNoteIssue::class);
    $input = ixNoteInput($original);

    $preview = $issue->preview($input);

    expect($preview)->toMatchArray(['totals' => ['net' => 10000, 'tax' => 2300, 'total' => 12300], 'remaining' => ['quantity' => 3, 'total' => 36900]])
        ->and(fn () => $issue->confirm($input, $preview['revision']))->toThrow(ValidationException::class);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    expect(InvoiceXpressNoteAttempt::query()->count())->toBe(0);
})->group('integration');

it('checks the remaining original line against prior provider notes and rejects a stale preview', function () {
    $original = ixNoteOriginal();
    $related = [];
    ixNoteFake(function () use (&$related) {
        return $related;
    });
    $issue = app(InvoiceXpressNoteIssue::class);
    $input = ixNoteInput($original);

    $preview = $issue->preview($input);
    $related = [ixNoteDocument('CreditNote', quantity: 2)];

    expect(fn () => $issue->confirm($input, $preview['revision']))->toThrow(ValidationException::class)
        ->and(fn () => $issue->preview(ixNoteInput($original, quantity: 2)))->toThrow(ValidationException::class)
        ->and($issue->preview($input)['remaining'])->toBe(['quantity' => 1, 'total' => 12300]);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
})->group('integration');

it('rejects unverifiable selections or reasons before any provider write', function (array $override, array $originalOverrides) {
    $original = ixNoteOriginal();
    ixNoteFake(fn () => [], originalOverrides: $originalOverrides);

    expect(fn () => app(InvoiceXpressNoteIssue::class)->preview([...ixNoteInput($original), ...$override]))
        ->toThrow(ValidationException::class);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
})->with([
    'unknown line' => [['line' => 2], []],
    'empty reason' => [['reason' => '   '], []],
    'unapproved catalog item' => [['itemId' => 91], []],
    'changed original amount' => [[], ['total' => 368]],
    'ambiguous duplicate original lines' => [[], ['items' => [ixNoteLine(1), ixNoteLine(2)]]],
])->group('integration');

it('checks remaining cents even when a whole unit is still available after rounding', function () {
    $originalTerms = [
        'before_taxes' => '0.09', 'taxes' => '0.02', 'total' => '0.11',
        'items' => [[...ixNoteLine(3), 'unit_price' => '0.03', 'subtotal' => '0.09', 'tax_amount' => '0.02', 'total' => '0.11']],
    ];
    $original = ixNoteOriginal(overrides: $originalTerms);
    $prior = [
        ...ixNoteDocument('CreditNote', quantity: 1),
        'before_taxes' => '0.03', 'taxes' => '0.01', 'total' => '0.04',
        'items' => [[...ixNoteLine(1), 'unit_price' => '0.03', 'subtotal' => '0.03', 'tax_amount' => '0.01', 'total' => '0.04']],
    ];
    ixNoteFake(fn () => [$prior, [...$prior, 'id' => 72]], originalOverrides: $originalTerms, itemOverrides: ['unit_price' => '0.03']);

    expect(fn () => app(InvoiceXpressNoteIssue::class)->preview(ixNoteInput($original)))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('quantity'));
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
})->group('integration');

it('uses the original discount on partial credits and refuses a final unit above the discounted residual', function () {
    $originalTerms = [...ixDiscountedNoteDocument(), 'discount' => '0.03'];
    $original = ixNoteOriginal(overrides: $originalTerms);
    $prior = ixDiscountedNoteDocument('CreditNote');
    $history = [$prior];
    ixNoteFake(function () use (&$history): array {
        return $history;
    }, originalOverrides: $originalTerms, itemOverrides: ['unit_price' => '0.08']);
    $issue = app(InvoiceXpressNoteIssue::class);
    $input = ixNoteInput($original);

    $preview = $issue->preview($input);

    expect($preview['original']['lines'][0]['discountBasisPoints'])->toBe(1250)
        ->and($preview['totals'])->toBe(['net' => 7, 'tax' => 2, 'total' => 9, 'discount' => 1])
        ->and($preview['remaining'])->toBe(['quantity' => 2, 'total' => 17])
        ->and($preview['payload']['items'][0])->toMatchArray(['unit_price_cents' => 8, 'discount_basis_points' => 1250]);

    $history[] = [...$prior, 'id' => 72];

    expect(fn () => $issue->preview($input))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('quantity'));
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
})->group('integration');

it('rejects historical credits whose discount percentage differs even when their cents agree', function () {
    $originalTerms = ixDiscountedNoteDocument();
    $original = ixNoteOriginal(overrides: $originalTerms);
    $prior = ixDiscountedNoteDocument('CreditNote');
    ixNoteFake(function () use (&$prior): array {
        return [$prior];
    }, originalOverrides: $originalTerms, itemOverrides: ['unit_price' => '0.08']);
    $issue = app(InvoiceXpressNoteIssue::class);
    $input = ixNoteInput($original);

    expect($issue->preview($input)['remaining']['total'])->toBe(17);

    $prior['items'][0]['discount'] = '12.51';

    expect(fn () => $issue->preview($input))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('line'));
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
})->group('integration');

it('blocks a zero-value debit note before writing to the provider', function () {
    $originalTerms = ixDiscountedNoteDocument();
    $originalTerms['before_taxes'] = $originalTerms['taxes'] = $originalTerms['total'] = '0.00';
    $originalTerms['items'][0] = [
        ...$originalTerms['items'][0], 'discount' => '100.00', 'discount_amount' => '0.24',
        'subtotal' => '0.00', 'tax_amount' => '0.00', 'total' => '0.00',
    ];
    $original = ixNoteOriginal(overrides: $originalTerms);
    ixNoteFake(fn () => [], originalOverrides: $originalTerms, itemOverrides: ['unit_price' => '0.08']);

    expect(fn () => app(InvoiceXpressNoteIssue::class)->preview(ixNoteInput($original, 'debit_note')))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('quantity'));
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
})->group('integration');

it('verifies a discounted draft before finalizing and stores exact terms on the attempt', function (bool $mismatched) {
    $originalTerms = ixDiscountedNoteDocument();
    $original = ixNoteOriginal(overrides: $originalTerms);
    $remote = ixDiscountedNoteDocument('CreditNote', 'draft');
    if ($mismatched) {
        $remote['items'][0]['discount'] = '12.51';
    }

    ixNoteFake(fn () => [], function (Request $request) use (&$remote) {
        return match ([$request->method(), parse_url($request->url(), PHP_URL_PATH)]) {
            ['POST', '/credit_notes.json'] => tap(Http::response(['credit_note' => ['id' => 71]], 201), function () use ($request, &$remote) {
                $remote['reference'] = $request['credit_note']['reference'];
            }),
            ['GET', '/credit_notes/71.json'] => Http::response(['credit_note' => $remote]),
            ['PUT', '/credit_notes/71/change-state.json'] => tap(Http::response('', 204), function () use (&$remote) {
                $remote['status'] = 'final';
            }),
            default => throw new LogicException('Pedido inesperado'),
        };
    }, originalOverrides: $originalTerms, itemOverrides: ['unit_price' => '0.08']);
    $issue = app(InvoiceXpressNoteIssue::class);
    $input = ixNoteInput($original);

    $attempt = $issue->confirm($input, $issue->preview($input)['revision'])->fresh();

    $creation = Http::recorded(fn (Request $request): bool => $request->method() === 'POST')->sole()[0];
    expect($creation['credit_note']['items'][0]['unit_price'])->toBe('0.08')
        ->and($creation['credit_note']['items'][0]['discount'])->toBe(12.5);

    expect($attempt->snapshot['payload']['items'][0])->toMatchArray(['unit_price_cents' => 8, 'discount_basis_points' => 1250])
        ->and($attempt->snapshot['payload']['items'][0])->not->toHaveKeys(['unit_price', 'discount'])
        ->and($attempt->snapshot['totals'])->toBe(['net' => 7, 'tax' => 2, 'total' => 9, 'discount' => 1])
        ->and($attempt->state)->toBe($mismatched ? InvoiceXpressNoteAttempt::Blocked : InvoiceXpressNoteAttempt::Finalized)
        ->and(Http::recorded(fn (Request $request): bool => $request->method() === 'PUT'))->toHaveCount($mismatched ? 0 : 1);

    if (! $mismatched) {
        $note = FiscalDocument::query()->findOrFail($attempt->fiscal_document_id);
        expect($note->total_amount)->toBe(9)
            ->and($note->links->related_fiscal_document_id)->toBe($original->id);
    }
})->with([false, true])->group('integration');

it('finalizes and records only the note and the fiscal relationship', function (string $kind, string $providerType) {
    $original = ixNoteOriginal();
    $receivable = Receivable::factory()->create(['amount' => 36900]);
    $payment = Payment::factory()->for($receivable)->create(['amount' => 36900]);
    $original->links()->create(['receivable_id' => $receivable->id]);
    $remoteStatus = null;
    $remoteReference = null;
    ixNoteFake(
        fn () => [],
        function (Request $request) use (&$remoteStatus, &$remoteReference, $kind, $providerType) {
            $resource = "/{$kind}s";

            return match ([$request->method(), parse_url($request->url(), PHP_URL_PATH)]) {
                ['POST', $resource.'.json'] => tap(Http::response([$kind => ['id' => 71]], 201), function () use (&$remoteStatus, &$remoteReference, $request, $kind) {
                    $remoteStatus = 'draft';
                    $remoteReference = $request[$kind]['reference'];
                    expect($request[$kind]['owner_invoice_id'])->toBe(65)
                        ->and($request[$kind]['observations'])->toBe('Corrigir serviço')
                        ->and($request[$kind]['items'][0]['quantity'])->toBe(1);
                }),
                ['GET', $resource.'/71.json'] => Http::response([$kind => [...ixNoteDocument($providerType, $remoteStatus, 1), 'reference' => $remoteReference]]),
                ['PUT', $resource.'/71/change-state.json'] => tap(Http::response('', 200), function () use (&$remoteStatus, $request) {
                    expect($request['invoice']['state'])->toBe('finalized');
                    $remoteStatus = 'final';
                }),
                default => throw new LogicException('Pedido inesperado'),
            };
        },
    );
    $issue = app(InvoiceXpressNoteIssue::class);
    $input = ixNoteInput($original, $kind);
    $preview = $issue->preview($input);

    $attempt = $issue->confirm($input, $preview['revision']);
    $replayed = $issue->confirm($input, $preview['revision']);

    expect($attempt->state)->toBe(InvoiceXpressNoteAttempt::Finalized);
    $note = FiscalDocument::query()->findOrFail($attempt->fiscal_document_id);
    expect($replayed->id)->toBe($attempt->id)
        ->and($note->type->value)->toBe($kind)
        ->and($note->total_amount)->toBe(12300)
        ->and($note->links->related_fiscal_document_id)->toBe($original->id)
        ->and($note->links->receivable_id)->toBeNull()
        ->and($payment->fresh()->amount)->toBe(36900)
        ->and($receivable->fresh()->amount)->toBe(36900)
        ->and($original->fresh()->total_amount)->toBe(36900);
    $this->assertDatabaseCount('credit_applications', 0);
    $this->assertDatabaseCount('invoice_xpress_issue_attempts', 0);
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(1);
})->with([['credit_note', 'CreditNote'], ['debit_note', 'DebitNote']])->group('integration');

it('isolates original invoice selection by account', function () {
    $original = ixNoteOriginal();
    $other = Account::factory()->create();
    Http::preventStrayRequests();
    Http::fake();

    $other->users()->attach(auth()->id(), ['role' => 'owner']);
    CurrentAccount::run($other, function () use ($original) {
        InvoiceXpressConfig::store('pilot', 'other-fake-credential');

        expect(fn () => app(InvoiceXpressNoteIssue::class)->original($original->id))
            ->toThrow(NotFoundHttpException::class);
    });

    Http::assertNothingSent();
})->group('integration');

it('requires a current preview to save a note and hides definitive issuance', function () {
    $original = ixNoteOriginal(false);
    ixNoteFake(fn () => []);

    Livewire::test(InvoiceXpressNoteIssuePage::class)
        ->set('originalId', (string) $original->id)
        ->call('loadOriginal')->assertHasNoErrors()->assertSee('Consultoria')
        ->update(
            calls: [['method' => 'preview', 'params' => []]],
            updates: ['line' => '1', 'itemId' => '8', 'reason' => 'Corrigir serviço'],
        )
        ->assertHasNoErrors()->assertSee(__('Save draft'))
        ->assertDontSee(__('Confirm and issue note'))
        ->call('confirm')->assertHasErrors(['accepted'])
        ->update(
            calls: [['method' => 'saveDraft', 'params' => []]],
            updates: ['quantity' => '2', 'reason' => 'Corrigir duas unidades'],
        )
        ->assertSet('previewData', null)->assertHasErrors('confirmation');

    $this->assertDatabaseEmpty('fiscal_document_drafts');
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
})->group('ui');

it('recovers an ambiguous creation using the same UUID and refuses another pending correction', function (bool $legacy) {
    $original = ixNoteOriginal();
    $uid = null;
    $reference = null;
    $remote = null;
    ixNoteFake(
        function () use (&$remote): array {
            return $remote === null ? [] : [$remote];
        },
        function (Request $request) use (&$uid, &$reference, &$remote) {
            return match ([$request->method(), parse_url($request->url(), PHP_URL_PATH)]) {
                ['POST', '/credit_notes.json'] => (function () use ($request, &$uid, &$reference, &$remote) {
                    if ($uid !== null) {
                        expect($request['proprietary_uid'])->toBe($uid)
                            ->and($request['credit_note']['reference'])->toBe($reference);

                        return Http::response(['credit_note' => ['id' => 71]], 201);
                    }

                    $uid = $request['proprietary_uid'];
                    $reference = $request['credit_note']['reference'];
                    $remote = [...ixNoteDocument('CreditNote', 'draft', 1), 'reference' => $reference];

                    return Http::response([], 503);
                })(),
                ['GET', '/credit_notes/71.json'] => Http::response(['credit_note' => $remote]),
                ['PUT', '/credit_notes/71/change-state.json'] => tap(Http::response('', 204), function () use (&$remote) {
                    $remote['status'] = 'final';
                }),
                default => throw new LogicException('Pedido inesperado'),
            };
        },
    );
    $issue = app(InvoiceXpressNoteIssue::class);
    $input = ixNoteInput($original);
    $preview = $issue->preview($input);
    $alternative = ixNoteInput($original, quantity: 2);
    $alternativePreview = $issue->preview($alternative);

    $pending = $issue->confirm($input, $preview['revision']);
    expect($pending->state)->toBe(InvoiceXpressNoteAttempt::Pending)
        ->and(fn () => $issue->confirm($alternative, $alternativePreview['revision']))->toThrow(ValidationException::class);

    if ($legacy) {
        $snapshot = $pending->snapshot;
        $snapshot['payload']['items'][0] = [
            'name' => 'Consultoria', 'description' => 'Serviço', 'unit' => 'service',
            'unit_price' => '100.00', 'quantity' => 1, 'tax' => ['name' => 'IVA23'],
        ];
        $pending->forceFill(['snapshot' => $snapshot])->save();
    }

    config(['services.invoicexpress.catalog_safe_accounts' => []]);
    expect(fn () => $issue->recover($pending->id))->toThrow(ValidationException::class);
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(1);

    config(['services.invoicexpress.catalog_safe_accounts' => ['pilot']]);
    $recovered = $issue->recover($pending->id);

    expect($recovered->state)->toBe(InvoiceXpressNoteAttempt::Finalized)
        ->and(InvoiceXpressNoteAttempt::query()->count())->toBe(1);
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(2);
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'PUT'))->toHaveCount(1);
})->with(['integer snapshot' => false, 'legacy euro snapshot' => true])->group('integration');

it('blocks a draft whose original relationship or reason cannot be verified', function (array $override) {
    $original = ixNoteOriginal();
    $reference = null;
    ixNoteFake(
        fn () => [],
        function (Request $request) use (&$reference, $override) {
            return match ([$request->method(), parse_url($request->url(), PHP_URL_PATH)]) {
                ['POST', '/credit_notes.json'] => tap(Http::response(['credit_note' => ['id' => 71]], 201), function () use (&$reference, $request) {
                    $reference = $request['credit_note']['reference'];
                }),
                ['GET', '/credit_notes/71.json'] => Http::response(['credit_note' => [
                    ...ixNoteDocument('CreditNote', 'draft', 1), 'reference' => $reference, ...$override,
                ]]),
                default => throw new LogicException('Pedido inesperado'),
            };
        },
    );
    $issue = app(InvoiceXpressNoteIssue::class);
    $input = ixNoteInput($original);

    $attempt = $issue->confirm($input, $issue->preview($input)['revision']);

    expect($attempt->state)->toBe(InvoiceXpressNoteAttempt::Blocked);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    expect(FiscalDocument::query()->count())->toBe(1);
})->with([
    'another invoice' => [['owner_invoice_id' => 99]],
    'different reason' => [['observations' => 'Outro motivo']],
    'missing definitive sequence' => [['sequence_id' => null]],
])->group('integration');

it('refuses to finalize a verified draft when catalogue safety was revoked', function () {
    $original = ixNoteOriginal();
    $reference = null;
    ixNoteFake(
        fn () => [],
        function (Request $request) use (&$reference) {
            return match ([$request->method(), parse_url($request->url(), PHP_URL_PATH)]) {
                ['POST', '/credit_notes.json'] => tap(Http::response(['credit_note' => ['id' => 71]], 201), function () use (&$reference, $request) {
                    $reference = $request['credit_note']['reference'];
                }),
                ['GET', '/credit_notes/71.json'] => tap(Http::response(['credit_note' => [
                    ...ixNoteDocument('CreditNote', 'draft', 1), 'reference' => $reference,
                ]]), fn () => config(['services.invoicexpress.catalog_safe_accounts' => []])),
                default => throw new LogicException('Pedido inesperado'),
            };
        },
    );
    $issue = app(InvoiceXpressNoteIssue::class);
    $input = ixNoteInput($original);
    $preview = $issue->preview($input);

    expect(fn () => $issue->confirm($input, $preview['revision']))->toThrow(ValidationException::class)
        ->and(InvoiceXpressNoteAttempt::query()->sole()->state)->toBe(InvoiceXpressNoteAttempt::Draft);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
})->group('integration');

it('refuses an account member who does not own the account before reading the provider', function () {
    $original = ixNoteOriginal();
    $member = User::factory()->create();
    $this->account->users()->attach($member, ['role' => 'member']);
    $this->actingAs($member);
    Http::preventStrayRequests();
    Http::fake();

    expect(fn () => app(InvoiceXpressNoteIssue::class)->original($original->id))
        ->toThrow(fn (HttpException $exception) => expect($exception->getStatusCode())->toBe(403));
    Http::assertNothingSent();
})->group('integration');

it('refuses a local fiscal note without verifiable provider history', function () {
    $original = ixNoteOriginal();
    $localNote = FiscalDocument::factory()->create(['type' => 'credit_note', 'direction' => 'issued', 'status' => 'active']);
    $localNote->links()->create(['related_fiscal_document_id' => $original->id]);
    ixNoteFake(fn () => []);

    expect(fn () => app(InvoiceXpressNoteIssue::class)->preview(ixNoteInput($original)))->toThrow(ValidationException::class);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
})->group('integration');

function ixNoteFiscalTerms(string $type = 'Invoice', string $status = 'final', ?string $exemption = null, int $rate = 2300): array
{
    $invoice = $type === 'Invoice';
    $quantity = $invoice ? 3 : 1;
    $tax = $exemption === null ? 23 * $quantity : 0;
    $retained = $rate === 2300 ? 23 * $quantity : ($rate === 1150 ? 11.5 * $quantity : 0);

    return [...ixNoteDocument($type, $status, $quantity),
        'before_taxes' => 100 * $quantity, 'taxes' => $tax, 'total' => 100 * $quantity + $tax - $retained,
        'retention' => $rate / 100, 'tax_exemption' => $exemption ?? 'M00',
        'items' => [[...ixNoteLine($quantity),
            'retention' => $rate / 100, 'tax_amount' => $tax, 'total' => 100 * $quantity + $tax,
            'tax' => ['id' => 9, 'name' => $exemption === null ? 'IVA23' : 'Isento', 'value' => $exemption === null ? 23 : 0],
        ]],
    ];
}

it('carries the confirmed original zero VAT reason into both note types without new tax choices', function (string $kind, string $reason) {
    $terms = ixNoteFiscalTerms(exemption: $reason, rate: 0);
    if ($reason === 'M40') {
        $terms['client'] = [...$terms['client'], 'country' => 'Spain', 'fiscal_id' => 'ESB12345678'];
    }
    $original = ixNoteOriginal(overrides: $terms);
    ixNoteFake(fn () => [], originalOverrides: $terms, itemOverrides: $terms['items'][0]);
    $preview = app(InvoiceXpressNoteIssue::class)->preview(ixNoteInput($original, $kind));

    expect($preview['payload']['tax_exemption_reason'])->toBe($reason)
        ->and($preview['totals'])->toBe(['net' => 10000, 'tax' => 0, 'total' => 10000])
        ->and($preview['original']['lines'][0]['exemptionReason'])->toBe($reason);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
})->with(['credit_note', 'debit_note'])->with(['M10', 'M40'])->group('integration');

it('calculates retained amounts from the confirmed original withholding rate', function () {
    $terms = ixNoteFiscalTerms();
    $original = ixNoteOriginal();
    ixNoteFake(fn () => [], originalOverrides: $terms, itemOverrides: $terms['items'][0]);
    $preview = app(InvoiceXpressNoteIssue::class)->preview(ixNoteInput($original));

    expect($preview['withholding'])->toBe(['basisPoints' => 2300, 'gross' => 12300, 'retained' => 2300, 'cash' => 10000])
        ->and($preview['payload']['retention_basis_points'])->toBe(2300)
        ->and($preview['payload']['items'][0]['retention_basis_points'])->toBe(2300)
        ->and($preview['remaining'])->toMatchArray(['total' => 36900, 'retained' => 6900, 'cash' => 30000]);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
})->group('integration');

it('rejects inconsistent exemption and retention evidence', function (array $changes) {
    $terms = array_replace_recursive(ixNoteFiscalTerms(), $changes);

    expect(fn () => InvoiceXpressNoteTerms::lines($terms))->toThrow(ValidationException::class);
})->with([
    'retained amount mistaken for percentage' => [['retention' => '69.00']],
    'cash one cent off' => [['total' => '300.01']],
    'gross reported as cash' => [['total' => '369.00']],
    'negative note representation' => [['type' => 'CreditNote', 'before_taxes' => '-300.00', 'taxes' => '-69.00', 'total' => '-300.00']],
    'retained one cent off' => [['retention' => '69.01']],
    'line rate different from the header' => [['items' => [['retention' => '11.50']]]],
    'line rate without header withholding' => [['retention' => 0]],
    'line total one cent off' => [['items' => [['total' => '369.01']]]],
    'zero VAT without original reason' => [['items' => [['tax' => ['value' => 0]]]]],
    'positive VAT with exemption' => [['tax_exemption' => 'M10']],
    'unsupported original exemption' => [['tax_exemption' => 'M99']],
])->group('integration');

it('rejects an approved catalog item whose tax or withholding differs from the original', function (array $override) {
    $terms = ixNoteFiscalTerms();
    $original = ixNoteOriginal();
    ixNoteFake(fn () => [], originalOverrides: $terms, itemOverrides: array_replace_recursive($terms['items'][0], $override));

    expect(fn () => app(InvoiceXpressNoteIssue::class)->preview(ixNoteInput($original)))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('itemId'));
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
})->with([
    'withholding rate' => [['retention' => '11.50']],
    'present tax ID' => [['tax' => ['id' => 999]]],
])->group('integration');

it('keeps retained note writes blocked until the withholding sandbox is validated', function () {
    $terms = ixNoteFiscalTerms();
    $original = ixNoteOriginal();
    config(['services.invoicexpress.withholding_validated_accounts' => []]);
    ixNoteFake(fn () => [], originalOverrides: $terms, itemOverrides: $terms['items'][0]);
    $issue = app(InvoiceXpressNoteIssue::class);
    $input = ixNoteInput($original);
    $preview = $issue->preview($input);

    expect(fn () => $issue->confirm($input, $preview['revision']))->toThrow(ValidationException::class)
        ->and(InvoiceXpressNoteAttempt::query()->count())->toBe(0);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
})->group('integration');

it('verifies both kinds of retained exempt draft before finalization', function (string $kind, string $type, array $draftOverride, bool $canFinalize) {
    $terms = ixNoteFiscalTerms(exemption: 'M10');
    $original = ixNoteOriginal(overrides: ['taxes' => 0, 'total' => 300]);
    config(['services.invoicexpress.withholding_validated_accounts' => ['pilot']]);
    $remote = array_replace_recursive(ixNoteFiscalTerms($type, 'draft', 'M10'), $draftOverride);
    unset($remote['atcud'], $remote['inverted_sequence_number']);
    ixNoteFake(fn () => [], function (Request $request) use (&$remote, $kind) {
        $resource = "/{$kind}s";

        return match ([$request->method(), parse_url($request->url(), PHP_URL_PATH)]) {
            ['POST', $resource.'.json'] => tap(Http::response([$kind => ['id' => 71]], 201), function () use ($request, &$remote, $kind) {
                $remote['reference'] = $request[$kind]['reference'];
            }),
            ['GET', $resource.'/71.json'] => Http::response([$kind => $remote]),
            ['PUT', $resource.'/71/change-state.json'] => Http::response([], 503),
            default => throw new LogicException('Pedido inesperado'),
        };
    }, originalOverrides: $terms, itemOverrides: $terms['items'][0]);
    $issue = app(InvoiceXpressNoteIssue::class);
    $input = ixNoteInput($original, $kind);

    $attempt = $issue->confirm($input, $issue->preview($input)['revision']);

    $creation = Http::recorded(fn (Request $request): bool => $request->method() === 'POST')->sole()[0];
    expect($creation[$kind]['tax_exemption_reason'])->toBe('M10')
        ->and($creation[$kind]['retention'])->toBe('23.00')
        ->and($creation[$kind]['items'][0]['retention'])->toBe(23.0)
        ->and($attempt->snapshot['withholding'])->toBe(['basisPoints' => 2300, 'gross' => 10000, 'retained' => 2300, 'cash' => 7700])
        ->and($attempt->state)->toBe($canFinalize ? InvoiceXpressNoteAttempt::Draft : InvoiceXpressNoteAttempt::Blocked)
        ->and(Http::recorded(fn (Request $request): bool => $request->method() === 'PUT'))->toHaveCount($canFinalize ? 1 : 0);
    expect(FiscalDocument::query()->count())->toBe(1);
})->with([
    'credit note matches' => ['credit_note', 'CreditNote', [], true],
    'debit note matches' => ['debit_note', 'DebitNote', [], true],
    'wrong exemption' => ['credit_note', 'CreditNote', ['tax_exemption' => 'M40'], false],
    'wrong line rate' => ['debit_note', 'DebitNote', ['items' => [['retention' => 11.5]], 'retention' => '11.50', 'total' => '88.50'], false],
    'one cent off' => ['credit_note', 'CreditNote', ['total' => '77.01'], false],
])->group('integration');

it('counts a historical note against the retained and cash balances of the original line', function () {
    $terms = ixNoteFiscalTerms();
    $original = ixNoteOriginal();
    $prior = ixNoteFiscalTerms('CreditNote');
    ixNoteFake(function () use (&$prior): array {
        return [$prior];
    }, originalOverrides: $terms, itemOverrides: $terms['items'][0]);
    $issue = app(InvoiceXpressNoteIssue::class);
    $input = ixNoteInput($original);

    expect($issue->preview($input)['remaining'])->toBe(['quantity' => 2, 'total' => 24600, 'retained' => 4600, 'cash' => 20000]);

    $prior = ixNoteFiscalTerms('CreditNote', rate: 1150);

    expect(fn () => $issue->preview($input))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('line'));
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
})->group('integration');

it('blocks an ambiguous provider replay when two linked drafts match the saved reference', function () {
    $original = ixNoteOriginal();
    $history = [];
    ixNoteFake(function () use (&$history): array {
        return $history;
    }, function (Request $request) use (&$history) {
        expect($request->method())->toBe('POST');
        $note = [...ixNoteDocument('CreditNote', 'draft', 1), 'reference' => $request['credit_note']['reference']];
        $history = [$note, [...$note, 'id' => 72]];

        return Http::response([], 503);
    });
    $issue = app(InvoiceXpressNoteIssue::class);
    $input = ixNoteInput($original);

    $attempt = $issue->confirm($input, $issue->preview($input)['revision']);

    expect($attempt->state)->toBe(InvoiceXpressNoteAttempt::Pending)
        ->and($issue->recover($attempt->id)->state)->toBe(InvoiceXpressNoteAttempt::Blocked)
        ->and(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(1);
})->group('integration');

it('inherits the header withholding when the provider does not return a line rate', function () {
    $original = ixNoteOriginal();
    $terms = ixNoteFiscalTerms();
    unset($terms['items'][0]['retention']);
    ixNoteFake(fn () => [], originalOverrides: $terms);

    $preview = app(InvoiceXpressNoteIssue::class)->preview(ixNoteInput($original));

    expect($preview['withholding'])->toBe(['basisPoints' => 2300, 'gross' => 12300, 'retained' => 2300, 'cash' => 10000])
        ->and($preview['payload']['items'][0])->not->toHaveKey('retention_basis_points');
})->group('integration');

it('imports a verified retained note at its gross amount and counts the local note in later previews', function (string $kind, string $type) {
    $terms = ixNoteFiscalTerms();
    $original = ixNoteOriginal(overrides: $terms);
    config(['services.invoicexpress.withholding_validated_accounts' => ['pilot']]);
    $note = null;
    ixNoteFake(function () use (&$note): array {
        return $note === null ? [] : [$note];
    }, function (Request $request) use (&$note, $kind, $type) {
        $resource = "/{$kind}s";

        return match ([$request->method(), parse_url($request->url(), PHP_URL_PATH)]) {
            ['POST', $resource.'.json'] => tap(Http::response([$kind => ['id' => 71]], 201), function () use ($request, &$note, $kind, $type) {
                $note = [...ixNoteFiscalTerms($type, 'draft'), 'reference' => $request[$kind]['reference']];
            }),
            ['GET', $resource.'/71.json'] => Http::response([$kind => $note]),
            ['PUT', $resource.'/71/change-state.json'] => tap(Http::response('', 204), function () use (&$note) {
                $note['status'] = 'final';
            }),
            default => throw new LogicException('Pedido inesperado'),
        };
    }, originalOverrides: $terms, itemOverrides: $terms['items'][0]);
    $issue = app(InvoiceXpressNoteIssue::class);
    $input = ixNoteInput($original, $kind);
    $preview = $issue->preview($input);
    $attempt = $issue->confirm($input, $preview['revision']);

    expect($attempt->state)->toBe(InvoiceXpressNoteAttempt::Finalized)
        ->and($issue->confirm($input, $preview['revision'])->id)->toBe($attempt->id);
    $document = FiscalDocument::query()->findOrFail($attempt->fiscal_document_id);
    expect($document->total_amount)->toBe(12300)
        ->and($document->links->related_fiscal_document_id)->toBe($original->id)
        ->and($document->invoiceXpressSnapshot->source['withholding'])->toBe(['basisPoints' => 2300, 'gross' => 12300, 'retained' => 2300, 'cash' => 10000])
        ->and($issue->preview($input)['remaining'])->toBe(['quantity' => 2, 'total' => 24600, 'retained' => 4600, 'cash' => 20000])
        ->and(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(1);
    $this->assertDatabaseEmpty('withholding_records');
})->with([['credit_note', 'CreditNote'], ['debit_note', 'DebitNote']])->group('integration');

it('creates a note only as a sandbox draft and verifies its original through the related documents', function (string $kind, string $type) {
    $terms = ixNoteFiscalTerms(exemption: 'M10');
    unset($terms['items'][0]['retention']);
    $terms['client']['fiscal_id'] = '999999990';
    $original = ixNoteOriginal(overrides: $terms);
    config(['services.invoicexpress.sandbox_draft_account' => 'pilot']);
    $settings = Setting::query()->where('key', 'invoice_xpress_issue')->sole();
    $settings->update(['value' => [...$settings->value, 'draft_only' => true]]);
    $draft = null;
    $priorDraft = [...ixNoteDocument('CreditNote', 'draft', 1), 'id' => 72, 'reference' => 'Other draft'];
    ixNoteFake(function () use (&$draft, $priorDraft): array {
        return $draft === null ? [$priorDraft] : [$priorDraft, $draft];
    }, function (Request $request) use (&$draft, $kind, $type, $terms) {
        $resource = "/{$kind}s";
        if ($request->method() === 'POST' && parse_url($request->url(), PHP_URL_PATH) === $resource.'.json') {
            $draft = [...ixNoteFiscalTerms($type, 'draft', 'M10'), 'reference' => $request[$kind]['reference'], 'client' => $terms['client']];
            unset($draft['owner_invoice_id'], $draft['atcud'], $draft['inverted_sequence_number'], $draft['sequence_id'], $draft['items'][0]['retention']);

            return Http::response([$kind => $draft], 201);
        }

        return match ([$request->method(), parse_url($request->url(), PHP_URL_PATH)]) {
            ['GET', $resource.'/71.json'] => Http::response([$kind => $draft]),
            default => throw new LogicException('Pedido inesperado'),
        };
    }, originalOverrides: $terms, itemOverrides: [...$terms['items'][0], 'tax' => ['name' => 'Isento', 'value' => 0]], sequenceOverrides: [
        'serie' => InvoiceXpressSandboxDrafts::Series,
        "current_{$kind}_validation_code" => 'N/D',
    ], taxOverrides: ['code' => null]);
    $issue = app(InvoiceXpressNoteIssue::class);
    $input = ixNoteInput($original, $kind);
    $revision = $issue->preview($input)['revision'];

    expect(fn () => $issue->confirm($input, $revision))->toThrow(ValidationException::class);
    Livewire::test(InvoiceXpressNoteIssuePage::class)
        ->set('originalId', (string) $original->id)->call('loadOriginal')->assertHasNoErrors()
        ->set('kind', $kind)->set('line', '1')->set('itemId', '8')->set('reason', 'Corrigir serviço')
        ->call('preview')->assertHasNoErrors()->assertSee(__('Save draft'))
        ->call('saveDraft')->assertHasNoErrors()->assertSee(__('Saved in Contiu'));

    $saved = FiscalDocumentDraft::query()->sole();
    expect($saved->state)->toBe(FiscalDocumentDraft::Local)
        ->and($saved->document_type)->toBe($kind)
        ->and($saved->remote_id)->toBeNull()
        ->and($saved->payload['inputs'])->toMatchArray($input);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');

    Livewire::withQueryParams(['draft' => $saved->id])->test(InvoiceXpressNoteIssuePage::class)
        ->assertSet('kind', $kind)
        ->assertSet('originalId', (string) $original->id)
        ->call('loadOriginal')->assertHasNoErrors()->assertSet('line', '1');

    expect($issue->createDraft($input, $revision))->toBe(71);
    $draft['client']['fiscal_id'] = '999999991';
    expect(fn () => $issue->createDraft($input, $revision))->toThrow(ValidationException::class);
    $draft['client'] = $terms['client'];
    $draft['sequence_id'] = 999;
    expect(fn () => $issue->createDraft($input, $revision))->toThrow(ValidationException::class);
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    expect(FiscalDocument::query()->count())->toBe(1)
        ->and(InvoiceXpressNoteAttempt::query()->count())->toBe(0);
})->with([['credit_note', 'CreditNote'], ['debit_note', 'DebitNote']])->group('integration');

it('previews a credit note for an invoice receipt and prevents debit notes against it', function () {
    $terms = [...ixNoteFiscalTerms(exemption: 'M10'), 'type' => 'InvoiceReceipt', 'status' => 'settled'];
    $original = ixNoteOriginal(overrides: $terms);
    ixNoteFake(fn () => [], originalOverrides: $terms, itemOverrides: $terms['items'][0]);
    $issue = app(InvoiceXpressNoteIssue::class);

    expect($issue->preview(ixNoteInput($original))['payload'])->toMatchArray(['owner_invoice_id' => 65, 'tax_exemption_reason' => 'M10'])
        ->and(fn () => $issue->preview(ixNoteInput($original, 'debit_note')))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('kind'));
    Livewire::test(InvoiceXpressNoteIssuePage::class)
        ->assertSee($original->number)
        ->set('kind', 'debit_note')
        ->set('originalId', (string) $original->id)
        ->call('loadOriginal')->assertHasNoErrors()->assertSet('kind', 'credit_note')
        ->set('line', '1')->set('itemId', '8')->set('reason', 'Corrigir serviço')
        ->call('preview')->assertHasNoErrors()->assertSet('previewData.exemptionReason', 'M10')
        ->assertSet('previewData.withholding.cash', 7700);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && parse_url($request->url(), PHP_URL_PATH) === '/invoice_receipts/65.json');
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET' || parse_url($request->url(), PHP_URL_PATH) === '/invoices/65.json');
})->group('integration');

it('refuses an original whose retained terms have changed even when its gross amount is unchanged', function () {
    $original = ixNoteOriginal(overrides: ixNoteFiscalTerms());
    $terms = ixNoteFiscalTerms(rate: 1150);
    ixNoteFake(fn () => [], originalOverrides: $terms, itemOverrides: $terms['items'][0]);

    expect(fn () => app(InvoiceXpressNoteIssue::class)->preview(ixNoteInput($original)))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('originalId'));
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
})->group('integration');
