<?php

use App\Filament\Pages\InvoiceXpressIssuePage;
use App\Models\InvoiceXpressConfig;
use App\Models\InvoiceXpressIssueAttempt;
use App\Models\Payment;
use App\Support\InvoiceXpressImport;
use App\Support\InvoiceXpressIssue;
use App\Support\InvoiceXpressIssueRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

pest()->group('invoicexpress');

it('recovers an uncertain direct invoice without duplicating a charge or linking an edited local document', function (bool $edited) {
    $existing = invoiceXpressIssueReceivable();
    $direct = ['key' => '8c832a1a-fbe1-4d6a-a036-36263d199183', 'clientId' => $existing->client->id, 'dueOn' => '2026-12-10'];
    test()->invoiceXpressIssueReference = 'Contiu '.$direct['key'];
    $response = fn (string $status): array => invoiceXpressIssueResponse($status, [
        'before_taxes' => 270.13, 'taxes' => 62.13, 'total' => 332.26, 'discount' => 30.02,
        'items' => [[
            'name' => 'Consultoria', 'description' => 'Serviço', 'unit' => 'service', 'unit_price' => '100.05',
            'quantity' => 3, 'discount' => 10, 'discount_amount' => 30.02, 'subtotal' => 270.13, 'tax_amount' => 62.13, 'total' => 332.26,
            'tax' => ['id' => 9, 'name' => 'IVA Contiu', 'value' => 23],
        ]],
    ]);
    $final = false;
    $finalizations = 0;
    invoiceXpressIssueFake(function (Request $request) use (&$final, &$finalizations, $response) {
        if ($request->method() === 'PUT') {
            $finalizations++;
            $final = true;
            throw new ConnectionException('Response lost');
        }

        return Http::response($request->method() === 'POST' ? ['invoice' => ['id' => 65]] : $response($final ? 'final' : 'draft'));
    });

    $service = app(InvoiceXpressIssue::class);
    $preview = $service->preview(0, 42, 8, 3, discountBasisPoints: 1000, direct: $direct);
    $attempt = $service->confirm(0, 42, 8, 3, $preview['revision'], discountBasisPoints: 1000, direct: $direct);
    expect($attempt->state)->toBe(InvoiceXpressIssueAttempt::Draft);
    $this->assertDatabaseCount('receivables', 1);

    $newDraft = [...$direct, 'key' => 'f1a51bae-d523-4af3-926a-b626a5d41c6f'];
    $newPreview = $service->preview(0, 42, 8, 3, discountBasisPoints: 1000, direct: $newDraft);
    expect(fn () => $service->confirm(0, 42, 8, 3, $newPreview['revision'], discountBasisPoints: 1000, direct: $newDraft))
        ->toThrow(ValidationException::class, 'tentativa por resolver');

    $imported = app(InvoiceXpressImport::class)->confirmInvoice($response('final')['invoice']);
    if ($edited) {
        $imported->update(['total_amount' => 33227]);
    }
    $recovered = $service->recover($attempt->id);
    if ($edited) {
        expect($recovered->state)->toBe(InvoiceXpressIssueAttempt::Blocked)
            ->and($imported->fresh()->links)->toBeNull();
        $this->assertDatabaseCount('receivables', 1);
        $this->assertDatabaseCount('invoice_xpress_issue_attempts', 1);

        return;
    }

    $charge = $imported->fresh()->links->receivable;
    expect($recovered->state)->toBe(InvoiceXpressIssueAttempt::Finalized)
        ->and($recovered->fiscal_document_id)->toBe($imported->id)
        ->and($charge->outstanding)->toBe(33226)
        ->and($charge->client->id)->toBe($existing->client->id)
        ->and($service->confirm(0, 42, 8, 3, $preview['revision'], discountBasisPoints: 1000, direct: $direct)->id)->toBe($attempt->id);
    $this->assertDatabaseCount('fiscal_documents', 1);
    $this->assertDatabaseCount('receivables', 2);
    $this->assertDatabaseCount('payments', 0);
    expect($finalizations)->toBe(1)
        ->and(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request['invoice']['items'][0]['unit_price'] === 100.05
        && $request['invoice']['items'][0]['discount'] === 10);
})->with(['intact import' => false, 'edited import' => true])->group('integration');

it('can recover a previously reserved invoice snapshot with the original euro payload', function () {
    $charge = invoiceXpressIssueReceivable();
    invoiceXpressIssueFake(fn () => Http::response(['invoice' => ['id' => 65]], 201));
    $service = app(InvoiceXpressIssue::class);
    $preview = $service->preview($charge->id, 42, 8, 3);
    $legacy = $preview;
    unset($legacy['revision']);
    $legacy['invoice']['items'][0] = [
        'name' => 'Consultoria', 'description' => 'Serviço', 'unit' => 'service',
        'unit_price' => 100.05, 'quantity' => 3, 'tax' => ['name' => 'IVA Contiu'],
    ];
    $prior = app(InvoiceXpressIssueRepository::class)->reserve('receivable:'.$charge->id, hash('sha256', 'pilot'), $legacy, (int) auth()->id());

    expect($service->confirm($charge->id, 42, 8, 3, $preview['revision'])->id)->toBe($prior->id);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request['proprietary_uid'] === $prior->proprietary_uid && $request['invoice'] === $legacy['invoice']);
})->group('integration');

it('does not retry a pending draft creation once catalogue validation is absent', function () {
    $receivable = invoiceXpressIssueReceivable();
    invoiceXpressIssueFake(fn () => Http::response([], 503));
    $service = app(InvoiceXpressIssue::class);
    $preview = $service->preview($receivable->id, 42, 8, 3);
    $attempt = $service->confirm($receivable->id, 42, 8, 3, $preview['revision']);

    config(['services.invoicexpress.catalog_safe_accounts' => []]);
    expect(fn () => $service->recover($attempt->id))->toThrow(ValidationException::class)
        ->and($attempt->fresh()->state)->toBe(InvoiceXpressIssueAttempt::Pending);
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(1);
})->group('integration');

it('reuses the exact create payload after an uncertain create and recovers a completed PUT by GET', function (bool $providerBusy) {
    $receivable = invoiceXpressIssueReceivable();
    $service = app(InvoiceXpressIssue::class);
    $posts = [];
    $puts = 0;
    invoiceXpressIssueFake(function (Request $request) use (&$posts, &$puts, $providerBusy) {
        if ($request->method() === 'POST') {
            $posts[] = $request->data();

            if (count($posts) === 1) {
                if ($providerBusy) {
                    return Http::response(['errors' => [['error' => 'This document is being saved by another request. Please try again.']]], 422);
                }

                throw new ConnectionException('URL containing fake-test-credential');
            }

            return Http::response(['invoice' => ['id' => 65, 'status' => 'draft']], 201);
        }

        if ($request->method() === 'PUT') {
            $puts++;
            throw new ConnectionException('URL containing fake-test-credential');
        }

        return Http::response(invoiceXpressIssueResponse($puts === 0 ? 'draft' : 'final'));
    });

    $preview = $service->preview($receivable->id, 42, 8, 3);
    $first = $service->confirm($receivable->id, 42, 8, 3, $preview['revision']);
    expect($first->state)->toBe(InvoiceXpressIssueAttempt::Pending)
        ->and($first->provider_invoice_id)->toBeNull();

    $second = $service->recover($first->id);
    expect($second->state)->toBe(InvoiceXpressIssueAttempt::Draft)
        ->and($second->provider_invoice_id)->toBeNull()
        ->and($second->document_reference)->toBeNull();

    $result = $service->recover($first->id);

    expect($result->state)->toBe(InvoiceXpressIssueAttempt::Finalized)
        ->and($puts)->toBe(1)
        ->and($posts)->toHaveCount(2)
        ->and($posts[0])->toBe($posts[1]);
    $this->assertDatabaseCount('fiscal_documents', 1);
    $this->assertDatabaseCount('invoice_xpress_issue_attempts', 1);
})->with(['connection timeout' => false, 'provider busy with same key' => true])->group('integration');

it('keeps a pending attempt visible after a connection switch and sends nothing to the new provider account', function () {
    $receivable = invoiceXpressIssueReceivable();
    invoiceXpressIssueFake(fn () => Http::response([], 503));
    $service = app(InvoiceXpressIssue::class);
    $preview = $service->preview($receivable->id, 42, 8, 3);
    $attempt = $service->confirm($receivable->id, 42, 8, 3, $preview['revision']);
    $previousCalls = Http::recorded()->count();
    InvoiceXpressConfig::store('changed', 'fake-rotated-credential');

    Livewire::test(InvoiceXpressIssuePage::class)
        ->assertSee('Por confirmar no InvoiceXpress')
        ->assertDontSee('Pré-visualizar fatura')
        ->call('recover', $attempt->id)
        ->assertHasErrors(['confirmation']);

    expect(Http::recorded())->toHaveCount($previousCalls)
        ->and($attempt->fresh()->state)->toBe(InvoiceXpressIssueAttempt::Pending);
})->group('ui');

it('keeps receipt recovery from finalizing a draft when payment or catalogue approval changes', function (bool $paymentChanged) {
    $receivable = invoiceXpressIssueReceivable();
    $payment = Payment::factory()->for($receivable)->create(['amount' => $receivable->amount]);
    $unavailable = true;
    invoiceXpressIssueFake(function (Request $request) use (&$unavailable) {
        return match ($request->method()) {
            'POST' => Http::response(['invoice_receipt' => ['id' => 65]], 201),
            'GET' => $unavailable ? Http::response([], 503) : Http::response(invoiceXpressIssueReceiptResponse('draft')),
            default => throw new LogicException('Recovery must leave the provider draft untouched.'),
        };
    });

    $service = app(InvoiceXpressIssue::class);
    $preview = $service->preview($receivable->id, 42, 8, 3, documentType: 'invoice_receipt');
    $attempt = $service->confirm($receivable->id, 42, 8, 3, $preview['revision'], documentType: 'invoice_receipt');
    expect($attempt->state)->toBe(InvoiceXpressIssueAttempt::Draft);

    $unavailable = false;
    if ($paymentChanged) {
        $payment->update(['amount' => $payment->amount - 1]);
        expect($service->recover($attempt->id)->state)->toBe(InvoiceXpressIssueAttempt::Blocked);
    } else {
        config(['services.invoicexpress.catalog_safe_accounts' => []]);
        expect(fn () => $service->recover($attempt->id))->toThrow(ValidationException::class)
            ->and($attempt->fresh()->state)->toBe(InvoiceXpressIssueAttempt::Draft);
    }

    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    $this->assertDatabaseEmpty('fiscal_documents');
    $this->assertDatabaseCount('payments', 1);
})->with(['payment changes' => true, 'catalogue approval removed' => false])->group('integration');

it('recovers an already finalized remote invoice by reading it and refuses a second invoice for its charge', function () {
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

    invoiceXpressAllocatedAdvance($receivable);
    expect(fn () => $issue->preview($receivable->id, 42, 8, 3))
        ->toThrow(ValidationException::class, 'Verifica a tentativa de emissão existente');

    config(['services.invoicexpress.catalog_safe_accounts' => []]);
    $before = Http::recorded()->count();
    expect($issue->recover($attempt->id)->state)->toBe(InvoiceXpressIssueAttempt::Finalized);
    expect(Http::recorded()->slice($before)->map(fn ($pair) => $pair[0]->method())->values()->all())->toBe(['GET']);
    expect(fn () => $issue->preview($receivable->id, 42, 8, 3))
        ->toThrow(ValidationException::class, 'Já existe um documento de faturação ativo');
    $this->assertDatabaseCount('fiscal_documents', 2);
})->group('integration');
