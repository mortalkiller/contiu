<?php

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Filament\Pages\FiscalDocumentDraftsPage;
use App\Filament\Pages\InvoiceXpressIssuePage;
use App\Filament\Pages\InvoiceXpressNoteIssuePage;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Resources\FiscalDocuments\Pages\ListFiscalDocuments;
use App\Models\Account;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentDraft;
use App\Models\InvoiceXpressConfig;
use App\Models\Setting;
use App\Support\CurrentAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

pest()->group('invoicexpress', 'ui');

it('keeps manual recording available before a fiscal provider is connected', function () {
    actingAsAdmin();

    Livewire::test(ListFiscalDocuments::class)
        ->assertActionVisible('create')
        ->assertActionHidden('issueInvoice')
        ->assertActionHidden('synchronize')
        ->assertActionHidden('drafts');
});

it('offers preparation independently of remote delivery and keeps synchronization separate', function (bool $draftOnly) {
    $owner = actingAsAdmin();
    InvoiceXpressConfig::store('pilot', 'fake-test-credential');
    config(['services.invoicexpress.sandbox_draft_account' => 'pilot']);
    Setting::create(['key' => 'invoice_xpress_issue', 'value' => [
        'provider' => 'invoicexpress', 'user_id' => $owner->id, 'subdomain' => 'pilot',
        'item_ids' => [8], 'tax_name' => 'IVA Contiu', 'sequence_id' => 19, 'draft_only' => $draftOnly,
    ]]);
    Http::preventStrayRequests();

    Livewire::test(ListFiscalDocuments::class)
        ->assertActionVisible('issueInvoice')
        ->assertActionVisible('issueInvoiceReceipt')
        ->assertActionVisible('issueCreditNote')
        ->assertActionVisible('issueDebitNote')
        ->assertActionVisible('issueReceipt')
        ->assertActionVisible('synchronize')
        ->assertActionVisible('drafts')
        ->assertActionVisible('create');
})->with([false, true]);

it('opens the selected document type through the existing forms', function () {
    actingAsAdmin();
    InvoiceXpressConfig::store('pilot', 'fake-test-credential');
    Http::preventStrayRequests();

    Livewire::withQueryParams(['type' => 'invoice_receipt'])
        ->test(InvoiceXpressIssuePage::class)
        ->assertSet('documentType', 'invoice_receipt');

    Livewire::withQueryParams(['type' => 'debit_note'])
        ->test(InvoiceXpressNoteIssuePage::class)
        ->assertSet('kind', 'debit_note');
});

it('saves a validated invoice locally with the remote send capability disabled', function () {
    $receivable = invoiceXpressIssueReceivable();
    config(['services.invoicexpress.catalog_safe_accounts' => []]);
    invoiceXpressIssueFake(fn () => throw new LogicException('Unexpected provider request.'));

    Livewire::test(InvoiceXpressIssuePage::class)
        ->set('receivableId', (string) $receivable->id)
        ->set('customerId', '42')
        ->set('itemId', '8')
        ->set('quantity', '3')
        ->call('preview')
        ->assertHasNoErrors()
        ->assertSee(__('Save draft'))
        ->assertDontSee(__('Confirm and issue'))
        ->set('accepted', true)
        ->call('confirm')->assertHasErrors('confirmation')
        ->call('saveDraft')->assertHasNoErrors()
        ->assertSee(__('Saved in Contiu'));

    $draft = FiscalDocumentDraft::query()->sole();
    expect($draft->state)->toBe(FiscalDocumentDraft::Local)
        ->and($draft->remote_id)->toBeNull()
        ->and($draft->preview['totals']['total'])->toBe(36918)
        ->and($draft->payload['inputs']['receivableId'])->toBe($receivable->id);

    Livewire::test(FiscalDocumentDraftsPage::class)->assertTableActionHidden('send', $draft);

    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
});

it('opens the drafts page without contacting InvoiceXpress', function () {
    actingAsAdmin();
    InvoiceXpressConfig::store('pilot', 'fake-test-credential');
    Http::preventStrayRequests()->fake([]);

    Livewire::test(FiscalDocumentDraftsPage::class)
        ->assertSee(__('Drafts'));

    Http::assertNothingSent();
});

it('scopes the draft list and reopening to the current account and connection', function () {
    $owner = actingAsAdmin();
    InvoiceXpressConfig::store('pilot', 'fake-test-credential');
    config(['services.invoicexpress.sandbox_draft_account' => 'pilot']);
    Setting::create(['key' => 'invoice_xpress_issue', 'value' => [
        'provider' => 'invoicexpress', 'user_id' => $owner->id, 'subdomain' => 'pilot',
        'item_ids' => [8], 'tax_name' => 'IVA Contiu', 'sequence_id' => 19, 'draft_only' => true,
    ]]);
    Http::preventStrayRequests()->fake([]);
    $local = FiscalDocumentDraft::factory()->create([
        'provider_account' => 'pilot',
        'preview' => ['draftAccount' => 'pilot', 'sequence' => ['name' => 'INVOICEXPRESSDEMO']],
    ]);
    $remote = FiscalDocumentDraft::factory()->create([
        'provider_account' => 'pilot', 'state' => FiscalDocumentDraft::Remote, 'remote_id' => 65,
    ]);
    $otherConnection = FiscalDocumentDraft::factory()->create();
    $otherAccount = CurrentAccount::run(Account::factory()->create(), fn () => FiscalDocumentDraft::factory()->create(['provider_account' => 'pilot']));

    Livewire::test(FiscalDocumentDraftsPage::class)
        ->assertCanSeeTableRecords([$local, $remote])
        ->assertCanNotSeeTableRecords([$otherConnection, $otherAccount])
        ->assertTableActionVisible('send', $local)
        ->assertTableActionHidden('send', $remote)
        ->assertTableActionVisible('refresh', $remote);

    Livewire::withQueryParams(['draft' => $otherAccount->id])
        ->test(InvoiceXpressIssuePage::class)->assertNotFound();

    Livewire::withQueryParams(['draft' => $remote->id])
        ->test(InvoiceXpressIssuePage::class)
        ->assertSee(__('Draft in InvoiceXpress'))
        ->assertDontSee(__('Save draft'))
        ->call('saveDraft')->assertForbidden();

    Http::assertNothingSent();
});

it('restores normalized inputs for a locally saved invoice draft', function () {
    actingAsAdmin();
    InvoiceXpressConfig::store('pilot', 'fake-test-credential');
    Http::preventStrayRequests()->fake([]);
    $draft = FiscalDocumentDraft::factory()->create([
        'provider_account' => 'pilot',
        'document_type' => 'invoice_receipt',
        'payload' => ['inputs' => [
            'receivableId' => 104,
            'customerId' => 42,
            'itemId' => 8,
            'quantity' => 3,
            'documentType' => 'invoice_receipt',
            'discountBasisPoints' => 1255,
            'additionalLines' => [['itemId' => 12, 'quantity' => 2, 'discountBasisPoints' => 300]],
        ]],
    ]);

    Livewire::withQueryParams(['draft' => $draft->id])
        ->test(InvoiceXpressIssuePage::class)
        ->assertSet('documentType', 'invoice_receipt')
        ->assertSet('receivableId', '104')
        ->assertSet('customerId', '42')
        ->assertSet('discount', '12.55')
        ->assertSet('additionalLines.0', ['itemId' => '12', 'quantity' => '2', 'discount' => '3.00']);

    Http::assertNothingSent();
});

it('preselects only eligible originals for a contextual note without a provider request', function () {
    actingAsAdmin();
    InvoiceXpressConfig::store('pilot', 'fake-test-credential');
    Http::preventStrayRequests()->fake([]);

    $eligible = FiscalDocument::factory()->create([
        'type' => FiscalDocumentType::Invoice,
        'status' => FiscalDocumentStatus::Active,
        'direction' => FiscalDocumentDirection::Issued,
        'currency' => 'EUR',
    ]);
    $eligible->invoiceXpressSnapshot()->create([
        'subdomain' => 'pilot',
        'provider_id' => 65,
        'source_hash' => str_repeat('a', 64),
        'source' => ['provider_type' => 'Invoice'],
    ]);
    $manual = FiscalDocument::factory()->create();

    Livewire::withQueryParams(['originalId' => $eligible->id])
        ->test(InvoiceXpressNoteIssuePage::class)
        ->assertSet('originalId', (string) $eligible->id)
        ->assertSet('originalData', null);

    Livewire::withQueryParams(['originalId' => $manual->id])
        ->test(InvoiceXpressNoteIssuePage::class)->assertNotFound();

    Http::assertNothingSent();
});

it('refreshes a conflicted remote draft with a read and links a promoted fiscal document', function () {
    actingAsAdmin();
    InvoiceXpressConfig::store('pilot', 'fake-test-credential');
    $document = FiscalDocument::factory()->create();
    $blocked = FiscalDocumentDraft::factory()->create([
        'provider_account' => 'pilot',
        'state' => FiscalDocumentDraft::Blocked,
        'remote_id' => 65,
    ]);
    $promoted = FiscalDocumentDraft::factory()->create([
        'provider_account' => 'pilot',
        'state' => FiscalDocumentDraft::Promoted,
        'remote_id' => 66,
        'fiscal_document_id' => $document->id,
    ]);
    Http::preventStrayRequests()->fake(function (Request $request) {
        expect([$request->method(), parse_url($request->url(), PHP_URL_PATH)])->toBe(['GET', '/invoices/65.json']);

        return Http::response(['invoice' => ['id' => 65, 'type' => 'Invoice', 'status' => 'draft']]);
    });

    Livewire::test(FiscalDocumentDraftsPage::class)
        ->assertTableActionHidden('send', $blocked)
        ->assertTableActionHidden('open', $promoted)
        ->assertTableActionHasUrl('viewDocument', FiscalDocumentResource::getUrl('edit', ['record' => $document]), $promoted)
        ->callTableAction('refresh', $blocked)
        ->assertHasNoTableActionErrors();

    Http::assertSentCount(1);
});
