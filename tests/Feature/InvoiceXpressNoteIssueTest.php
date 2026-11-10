<?php

use App\Filament\Pages\InvoiceXpressNoteIssuePage;
use App\Models\Account;
use App\Models\FiscalDocument;
use App\Models\InvoiceXpressConfig;
use App\Models\InvoiceXpressNoteAttempt;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\Setting;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\InvoiceXpressImport;
use App\Support\InvoiceXpressNoteIssue;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

function ixNoteFake(Closure $documents, ?Closure $writes = null, array $originalOverrides = [], array $itemOverrides = []): void
{
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($documents, $writes, $originalOverrides, $itemOverrides) {
        return match (parse_url($request->url(), PHP_URL_PATH)) {
            '/invoices/65.json' => Http::response(['invoice' => array_replace(ixNoteDocument(), $originalOverrides)]),
            '/clients/42.json' => Http::response(['client' => ixNoteDocument()['client']]),
            '/items/8.json' => Http::response(['item' => ['id' => 8, ...ixNoteLine(3), ...$itemOverrides]]),
            '/taxes.json' => Http::response(['taxes' => [['id' => 9, 'name' => 'IVA23', 'region' => 'PT', 'code' => 'NOR', 'value' => 23]]]),
            '/sequences/19.json' => Http::response(['sequence' => [
                'id' => 19, 'serie' => 'PILOTO',
                'current_credit_note_sequence_id' => 21, 'current_credit_note_validation_code' => 'CREDIT01',
                'current_debit_note_sequence_id' => 22, 'current_debit_note_validation_code' => 'DEBIT001',
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
});

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
});

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
]);

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
});

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
})->with([['credit_note', 'CreditNote'], ['debit_note', 'DebitNote']]);

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
});

it('requires a new preview and an explicit confirmation in the note page', function () {
    $original = ixNoteOriginal(false);
    ixNoteFake(fn () => []);

    Livewire::test(InvoiceXpressNoteIssuePage::class)
        ->set('originalId', (string) $original->id)
        ->call('loadOriginal')->assertHasNoErrors()->assertSee('Consultoria')
        ->set('line', '1')->set('itemId', '8')->set('reason', 'Corrigir serviço')
        ->call('preview')->assertHasNoErrors()->assertSee('Confirmar emissão')
        ->call('confirm')->assertHasErrors(['accepted'])
        ->set('quantity', '2')->assertSet('previewData', null);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
});

it('recovers an ambiguous creation using the same UUID and refuses another pending correction', function () {
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

    config(['services.invoicexpress.catalog_safe_accounts' => []]);
    expect(fn () => $issue->recover($pending->id))->toThrow(ValidationException::class);
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(1);

    config(['services.invoicexpress.catalog_safe_accounts' => ['pilot']]);
    $recovered = $issue->recover($pending->id);

    expect($recovered->state)->toBe(InvoiceXpressNoteAttempt::Finalized)
        ->and(InvoiceXpressNoteAttempt::query()->count())->toBe(1);
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(2);
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'PUT'))->toHaveCount(1);
});

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
]);

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
});

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
});

it('refuses a local fiscal note without verifiable provider history', function () {
    $original = ixNoteOriginal();
    $localNote = FiscalDocument::factory()->create(['type' => 'credit_note', 'direction' => 'issued', 'status' => 'active']);
    $localNote->links()->create(['related_fiscal_document_id' => $original->id]);
    ixNoteFake(fn () => []);

    expect(fn () => app(InvoiceXpressNoteIssue::class)->preview(ixNoteInput($original)))->toThrow(ValidationException::class);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
});
