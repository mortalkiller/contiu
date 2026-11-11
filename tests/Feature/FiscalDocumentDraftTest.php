<?php

use App\Models\Account;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentDraft;
use App\Models\InvoiceXpressConfig;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\FiscalProviderRegistry;
use App\Support\InvoiceXpressIssueSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

pest()->group('integration', 'fiscal', 'invoicexpress');

it('stores encrypted draft inputs and preview without creating a fiscal document', function () {
    $payload = ['customerName' => 'Cliente confidencial'];
    $preview = ['totals' => ['net' => 10000, 'tax' => 2300, 'total' => 12300]];

    $draft = FiscalDocumentDraft::factory()->create([
        'provider_account' => ' Demo-Account ',
        'payload' => $payload,
        'preview' => $preview,
    ])->refresh();
    $sendKey = $draft->send_key;
    $stored = DB::table('fiscal_document_drafts')->where('id', $draft->id)->first();

    expect($draft->account_id)->toBe($this->account->id)
        ->and($draft->provider_account)->toBe('demo-account')
        ->and($draft->payload)->toBe($payload)
        ->and($draft->preview)->toBe($preview)
        ->and($draft->state)->toBe('local')
        ->and($draft->remote_id)->toBeNull()
        ->and($draft->fiscal_document_id)->toBeNull();
    expect(json_decode(Crypt::decryptString($stored->payload), true, flags: JSON_THROW_ON_ERROR))->toBe($payload);
    expect(json_decode(Crypt::decryptString($stored->preview), true, flags: JSON_THROW_ON_ERROR))->toBe($preview);
    expect($draft->toArray())->not->toHaveKey('payload')->not->toHaveKey('preview')->not->toHaveKey('send_key');
    expect(Str::isUuid($sendKey))->toBeTrue();

    $draft->update(['revision' => str_repeat('a', 64)]);
    expect($draft->refresh()->send_key)->toBe($sendKey);
    $this->assertDatabaseCount('fiscal_documents', 0);
});

it('rejects an InvoiceXpress URL as a provider account identity', function () {
    expect(fn () => FiscalDocumentDraft::factory()->create(['provider_account' => 'https://demo.invoicexpress.com']))
        ->toThrow(ValidationException::class);
});

it('scopes drafts and provider remote identifiers by account and provider account', function () {
    $first = FiscalDocumentDraft::factory()->create(['provider_account' => 'demo', 'remote_id' => 42]);
    FiscalDocumentDraft::factory()->create(['provider_account' => 'other-demo', 'remote_id' => 42]);
    $otherAccount = Account::factory()->create();

    CurrentAccount::run($otherAccount, function () use ($first): void {
        $second = FiscalDocumentDraft::factory()->create(['provider_account' => 'demo', 'remote_id' => 42]);

        expect(FiscalDocumentDraft::find($first->id))->toBeNull()
            ->and(FiscalDocumentDraft::sole()->id)->toBe($second->id)
            ->and($second->remote_id)->toBe(42);
    });
});

it('enforces unique remote identifiers within the provider connection', function () {
    $draft = FiscalDocumentDraft::factory()->create(['remote_id' => 42]);

    expect(fn () => DB::transaction(fn () => FiscalDocumentDraft::factory()->create([
        'provider_account' => strtoupper($draft->provider_account),
        'remote_id' => 42,
    ])))->toThrow(QueryException::class);
});

it('enforces unique send keys even across accounts', function () {
    $draft = FiscalDocumentDraft::factory()->create();
    $otherAccount = Account::factory()->create();

    expect(fn () => CurrentAccount::run($otherAccount, fn () => DB::transaction(
        fn () => FiscalDocumentDraft::factory()->create(['send_key' => $draft->send_key]),
    )))->toThrow(QueryException::class);
});

it('rejects linking a promoted draft to a fiscal document from another account', function () {
    $otherAccount = Account::factory()->create();
    $document = CurrentAccount::run($otherAccount, fn () => FiscalDocument::factory()->create());
    $draft = FiscalDocumentDraft::factory()->create();

    expect(fn () => DB::transaction(fn () => $draft->fiscalDocument()->associate($document)->save()))
        ->toThrow(QueryException::class);
});

it('resolves a promoted document and cascades both records when the account is deleted', function () {
    $document = FiscalDocument::factory()->create();
    $draft = FiscalDocumentDraft::factory()->for($document)->create(['state' => 'promoted']);

    expect($draft->fiscalDocument->is($document))->toBeTrue();

    $this->account->delete();

    $this->assertModelMissing($draft);
    $this->assertModelMissing($document);
});

it('discovers the active InvoiceXpress connection and exposes only permitted sandbox draft operations', function () {
    Http::preventStrayRequests();
    $registry = app(FiscalProviderRegistry::class);
    expect($registry->current())->toBeNull();

    $owner = actingAsAdmin();
    config([
        'services.invoicexpress.sandbox_draft_account' => 'demo',
        'services.invoicexpress.catalog_safe_accounts' => ['demo'],
    ]);
    InvoiceXpressConfig::store(' DEMO ', 'test-key');
    $provider = $registry->current();

    expect($provider->provider())->toBe('invoicexpress')
        ->and($provider->providerAccount())->toBe('demo')
        ->and($provider->draftDocumentTypes())->toBe([]);

    app(InvoiceXpressIssueSettings::class)->save('8', 'IVA', '19');
    expect($provider->draftDocumentTypes())->toBe([]);

    app(InvoiceXpressIssueSettings::class)->save('8', 'IVA', '19', draftOnly: true);
    expect($provider->draftDocumentTypes())->toBe(['invoice', 'invoice_receipt', 'credit_note', 'debit_note'])
        ->and($provider->canCreateRemoteDraft('credit_note'))->toBeTrue()
        ->and($provider->canCreateRemoteDraft('simplified_invoice'))->toBeFalse()
        ->and($provider->canFinalize())->toBeFalse();

    $this->actingAs(User::factory()->create());
    expect($provider->canCreateRemoteDraft('credit_note'))->toBeFalse();

    $this->actingAs($owner);
    config(['services.invoicexpress.sandbox_draft_account' => 'other-demo']);
    expect($provider->canCreateRemoteDraft('invoice'))->toBeFalse();
});

it('does not let an adapter reuse valid settings from another tenant or a reconnected provider account', function () {
    Http::preventStrayRequests();
    $owner = actingAsAdmin();
    config(['services.invoicexpress.sandbox_draft_account' => 'demo']);
    InvoiceXpressConfig::store('demo', 'test-key');
    app(InvoiceXpressIssueSettings::class)->save('8', 'IVA', '19', draftOnly: true);
    $registry = app(FiscalProviderRegistry::class);
    $provider = $registry->current();

    $otherAccount = Account::factory()->create();
    $otherAccount->users()->attach($owner, ['role' => 'owner']);
    CurrentAccount::run($otherAccount, function () use ($provider, $registry): void {
        InvoiceXpressConfig::store('demo', 'other-test-key');
        app(InvoiceXpressIssueSettings::class)->save('9', 'IVA', '21', draftOnly: true);

        expect($provider->options())->toBeNull()
            ->and($registry->current()->options()['itemIds'])->toBe([9]);
    });

    config(['services.invoicexpress.sandbox_draft_account' => 'new-demo']);
    InvoiceXpressConfig::store('new-demo', 'test-key');
    app(InvoiceXpressIssueSettings::class)->save('10', 'IVA', '25', draftOnly: true);

    expect($provider->options())->toBeNull()
        ->and($registry->current()->options()['itemIds'])->toBe([10]);
});
