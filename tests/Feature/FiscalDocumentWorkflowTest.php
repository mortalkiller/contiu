<?php

use App\Filament\Pages\FiscalDocumentDraftsPage;
use App\Filament\Pages\InvoiceXpressIssuePage;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentDraft;
use App\Models\Setting;
use App\Support\InvoiceXpressDraftSync;
use App\Support\InvoiceXpressIssue;
use App\Support\InvoiceXpressSandboxDrafts;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

pest()->group('invoicexpress', 'integration');

it('takes a saved invoice through sending and synchronization without issuing it from Contiu', function () {
    $charge = invoiceXpressIssueReceivable();
    config([
        'services.invoicexpress.catalog_safe_accounts' => [],
        'services.invoicexpress.sandbox_draft_account' => 'pilot',
    ]);
    $settings = Setting::query()->where('key', InvoiceXpressIssue::SettingsKey)->sole();
    $settings->update(['value' => [...$settings->value, 'draft_only' => true]]);
    $reference = null;
    $externallyIssued = false;
    $item = ['id' => 8, ...invoiceXpressIssueResponse()['invoice']['items'][0]];

    Http::preventStrayRequests()->fake(function (Request $request) use (&$reference, &$externallyIssued, $item) {
        return match ([$request->method(), parse_url($request->url(), PHP_URL_PATH)]) {
            ['GET', '/clients/42.json'] => Http::response(['client' => invoiceXpressIssueResponse()['invoice']['client']]),
            ['GET', '/items/8.json'] => Http::response(['item' => $item]),
            ['GET', '/taxes.json'] => Http::response(['taxes' => [['id' => 9, 'name' => 'IVA Contiu', 'region' => 'PT', 'value' => 23]]]),
            ['GET', '/sequences/19.json'] => Http::response(['sequence' => [
                'id' => 19, 'serie' => InvoiceXpressSandboxDrafts::Series, 'current_invoice_sequence_id' => 19,
            ]]),
            ['POST', '/invoices.json'] => Http::response(invoiceXpressIssueResponse(overrides: ['reference' => $reference = $request['invoice']['reference']]), 201),
            ['GET', '/invoices/65.json'] => Http::response(invoiceXpressIssueResponse($externallyIssued ? 'final' : 'draft', [
                'reference' => $reference,
                ...($externallyIssued ? ['inverted_sequence_number' => InvoiceXpressSandboxDrafts::Series.'/7'] : []),
            ])),
            default => throw new LogicException('Unexpected provider request.'),
        };
    });

    Livewire::test(InvoiceXpressIssuePage::class)
        ->set('receivableId', (string) $charge->id)
        ->set('customerId', '42')->set('itemId', '8')->set('quantity', '3')
        ->call('preview')->assertHasNoErrors()
        ->call('saveDraft')->assertHasNoErrors();
    $draft = FiscalDocumentDraft::query()->sole();
    expect($draft->state)->toBe(FiscalDocumentDraft::Local);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    $this->assertDatabaseEmpty('fiscal_documents');

    $page = Livewire::test(FiscalDocumentDraftsPage::class)
        ->callAction(TestAction::make('send')->table($draft))->assertHasNoActionErrors();
    expect($draft->fresh()->remote_id)->toBe(65);
    $this->assertDatabaseEmpty('fiscal_documents');

    $externallyIssued = true;
    $page->callAction(TestAction::make('refresh')->table($draft->fresh()))->assertHasNoActionErrors();
    $document = FiscalDocument::query()->sole();
    expect($document->total_amount)->toBe(36918)
        ->and($document->links->receivable_id)->toBe($charge->id)
        ->and($draft->fresh()->fiscal_document_id)->toBe($document->id)
        ->and(app(InvoiceXpressDraftSync::class)->refresh($draft->id)->fiscal_document_id)->toBe($document->id);
    $this->assertDatabaseCount('fiscal_documents', 1);
    $this->assertDatabaseEmpty('payments');
    expect(Http::recorded(fn (Request $request): bool => $request->method() !== 'GET'))->toHaveCount(1);
});
