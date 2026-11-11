<?php

use App\Actions\SyncInvoicingDocuments;
use App\Enums\FiscalDocumentType;
use App\Enums\IntegrationRecordState;
use App\Filament\Pages\IntegrationsPage;
use App\Filament\Pages\InvoicingProviderPage;
use App\Filament\Pages\InvoicingSyncPage;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Widgets\InvoicingSyncStats;
use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\IntegrationRecord;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\User;
use App\Support\AccountantAccess;
use App\Support\CurrentAccount;
use App\Support\Invoicing\Invoicing;
use App\Support\Invoicing\InvoicingRecords;
use App\Support\Invoicing\RemoteDocument;
use App\Support\Invoicing\RemoteDocumentState;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

pest()->group('invoicing', 'fiscal');

/*
 * The "Sincronização" screen: what differs from the invoicing provider, what only exists there
 * and the drafts still to issue, with the ways to synchronise, look into a period and import.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-12 10:30'));
    $this->actingAs(ownerOf($this->account));
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->account);
    $this->connection = invoicingConnection();

    $this->remote = fn (int $id, string $number, float $total, array $overrides = []): array => invoiceXpressDocument([
        'id' => $id, 'status' => 'final', 'inverted_sequence_number' => $number, 'before_taxes' => $total, 'total' => $total, ...$overrides,
    ])['invoice'];
    $this->list = fn (array $invoices): array => ['invoices' => $invoices, 'pagination' => ['current_page' => 1, 'total_pages' => 1]];

    /** A document of the app tied to its counterpart at the provider, which says $remoteTotal. */
    $this->tied = function (FiscalDocument $document, IntegrationRecordState $state, ?int $remoteTotal = null, RemoteDocumentState $remoteState = RemoteDocumentState::Issued): IntegrationRecord {
        $total = $remoteTotal ?? $document->total_amount;

        return InvoicingRecords::store($document, new RemoteDocument(
            'invoices', (string) (9000 + $document->id), $document->type, $remoteState, CarbonImmutable::parse('2026-10-01'), $total, 0, $total,
            number: $document->number, partyName: $document->party_name,
        ), $state);
    };

    /** A document that only exists at the provider. */
    $this->remoteOnly = fn (string $number, int $total = 12000, string $remoteId = '8812'): IntegrationRecord => IntegrationRecord::query()->create([
        'integration_connection_id' => Invoicing::connection()->id,
        'remote_type' => 'invoices',
        'remote_id' => $remoteId,
        'state' => IntegrationRecordState::RemoteOnly,
        'remote' => ['type' => FiscalDocumentType::Invoice->value, 'state' => 'issued', 'number' => $number, 'date' => '2026-10-22', 'net' => $total, 'vat' => 0, 'total' => $total, 'party_name' => 'Oficina Norte', 'party_tax_id' => '509999974', 'url' => null],
        'synced_at' => now(),
    ]);
});

it('lists what differs, what only exists at the provider and the drafts, and leaves out what is in sync', function () {
    $differing = ($this->tied)(FiscalDocument::factory()->create(['number' => 'FT 2026/9', 'party_name' => 'Estúdio Atlântico', 'total_amount' => 85000]), IntegrationRecordState::Differs, 80000);
    $cancelled = ($this->tied)(FiscalDocument::factory()->create(['number' => 'FT 2026/10']), IntegrationRecordState::Differs, remoteState: RemoteDocumentState::Canceled);
    $providerOnly = ($this->remoteOnly)('FT 2026/12');
    $draft = ($this->tied)(draftDocument(), IntegrationRecordState::InSync, remoteState: RemoteDocumentState::Draft);
    $inSync = ($this->tied)(FiscalDocument::factory()->create(['number' => 'FT 2026/13']), IntegrationRecordState::InSync);
    // The document's own page and the one where a draft is picked up, where the resource has them.
    $page = fn (string $name): string => array_key_exists($name, FiscalDocumentResource::getPages()) ? $name : 'edit';

    Livewire::test(InvoicingSyncPage::class)
        ->assertCanSeeTableRecords([$differing, $cancelled, $providerOnly, $draft])
        ->assertCanNotSeeTableRecords([$inSync])
        ->assertCountTableRecords(4)
        ->assertTableColumnStateSet('document', 'FT 2026/9', $differing)
        ->assertTableColumnStateSet('situation', __('Different total'), $differing)
        ->assertTableColumnStateSet('local_total', 85000, $differing)
        ->assertTableColumnStateSet('remote_total', 80000, $differing)
        ->assertTableColumnStateSet('situation', __('Cancelled in :provider', ['provider' => 'InvoiceXpress']), $cancelled)
        ->assertTableColumnStateSet('document', 'FT 2026/12', $providerOnly)
        ->assertTableColumnStateSet('situation', __('Only in :provider', ['provider' => 'InvoiceXpress']), $providerOnly)
        ->assertTableColumnStateSet('local_total', null, $providerOnly)
        ->assertTableColumnStateSet('document', __(':type to issue', ['type' => FiscalDocumentType::Invoice->getLabel()]), $draft)
        ->assertTableColumnStateSet('situation', __('Draft to issue'), $draft)
        ->assertTableActionHasUrl('review', FiscalDocumentResource::getUrl($page('view'), ['record' => $differing->record_id]), $differing)
        ->assertTableActionHidden('import', $differing)
        ->assertTableActionVisible('import', $providerOnly)
        ->assertTableActionHidden('review', $providerOnly)
        ->assertTableActionHasUrl('open', FiscalDocumentResource::getUrl($page('draft'), ['record' => $draft->record_id]), $draft)
        ->assertSee('Oficina Norte · 22/10/2026');
})->group('ui');

it('opens under the fiscal documents, with the ways to the provider and to synchronise', function () {
    $this->get(InvoicingSyncPage::getUrl())
        ->assertOk()
        ->assertSeeInOrder([__('Fiscal documents'), __('Synchronisation')])
        ->assertSee(FiscalDocumentResource::getUrl(), false);

    Livewire::test(InvoicingSyncPage::class)
        ->assertActionHasUrl('provider', InvoicingProviderPage::getUrl(['provider' => 'invoicexpress']))
        ->assertActionEnabled('sync')
        ->assertSeeLivewire(InvoicingSyncStats::class);
})->group('ui');

it('counts what is in sync, to issue, different and to import', function () {
    ($this->tied)(FiscalDocument::factory()->create(), IntegrationRecordState::InSync);
    // A draft at the provider and one that never left the app: both are to issue, neither is "in sync".
    ($this->tied)(draftDocument(), IntegrationRecordState::InSync, remoteState: RemoteDocumentState::Draft);
    FiscalDocument::factory()->draft()->create();
    FiscalDocument::factory()->count(3)->create()->each(fn (FiscalDocument $document) => ($this->tied)($document, IntegrationRecordState::Differs, 1));
    collect(range(1, 4))->each(fn (int $number) => ($this->remoteOnly)("FT 2026/2{$number}", remoteId: "88{$number}"));
    // A client known to the provider is no document.
    IntegrationRecord::query()->forceCreate([
        'integration_connection_id' => $this->connection->id, 'record_type' => (new Client)->getMorphClass(), 'record_id' => Client::factory()->create()->id,
        'remote_type' => 'clients', 'remote_id' => '77', 'state' => IntegrationRecordState::InSync, 'synced_at' => now(),
    ]);

    Livewire::test(InvoicingSyncStats::class)
        ->assertSeeInOrder([__('In sync'), '1', __('To issue'), '2', __('With differences'), '3', __('To import'), '4']);
})->group('ui');

it('asks the provider when told to synchronise now and shows what it found', function () {
    FiscalDocument::factory()->create(['number' => 'FT 2026/9', 'net_amount' => 85000, 'vat_amount' => 0, 'total_amount' => 85000]);
    fakeInvoiceXpress(['GET /invoices.json' => ($this->list)([
        ($this->remote)(8809, '2026/9', 800.0),
        ($this->remote)(8812, '2026/12', 120.0, ['client' => ['name' => 'Oficina Norte', 'fiscal_id' => '509999974']]),
    ])]);

    Livewire::test(InvoicingSyncPage::class)
        ->assertCountTableRecords(0)
        ->callAction('sync')
        ->assertNotified(__('Synchronised'))
        ->assertDispatched(InvoicingSyncStats::REFRESH_EVENT)
        ->assertCountTableRecords(2)
        ->assertSee(['FT 2026/9', 'FT 2026/12']);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/invoices.json'));
    expect($this->connection->fresh()->last_synced_at)->not->toBeNull();
});

it('says what went wrong when the provider fails and stays usable', function () {
    $providerOnly = ($this->remoteOnly)('FT 2026/12');
    fakeInvoiceXpress(['GET /invoices.json' => fn () => Http::response('', 503)]);

    $page = Livewire::test(InvoicingSyncPage::class)->callAction('sync');
    $error = $this->connection->fresh()->last_error;

    expect($error)->not->toBeNull();

    $page->assertNotified($error)
        ->assertSee($error)
        ->assertSee(__('Failed'))
        ->assertCanSeeTableRecords([$providerOnly])
        ->assertActionEnabled('sync');
});

it('imports a document that only exists at the provider from its row', function () {
    $client = Client::factory()->create(['vat_number' => '509999974']);
    $receivable = Receivable::factory()->for(Project::factory()->for($client), 'billable')->create(['amount' => 12000]);
    $remote = ($this->remote)(8812, '2026/12', 120.0, ['client' => ['name' => 'Oficina Norte', 'fiscal_id' => '509999974']]);
    fakeInvoiceXpress(['GET /invoices.json' => ($this->list)([$remote]), 'GET /invoices/8812.json' => ['invoice' => $remote]]);
    app(SyncInvoicingDocuments::class)->handle();
    $entry = IntegrationRecord::query()->sole();

    Livewire::test(InvoicingSyncPage::class)
        ->assertCanSeeTableRecords([$entry])
        ->mountAction(TestAction::make('import')->table($entry))
        ->assertMountedActionModalSee(['FT 2026/12', 'Oficina Norte', '509999974'])
        ->assertFormFieldExists('receivable_id', checkFieldUsing: fn (Select $field): bool => array_keys($field->getOptions()) === [$receivable->id])
        ->unmountAction()
        ->callAction(TestAction::make('import')->table($entry), ['receivable_id' => $receivable->id])
        ->assertHasNoFormErrors()
        ->assertNotified(__(':number imported', ['number' => 'FT 2026/12']))
        ->assertCanNotSeeTableRecords([$entry]);

    $document = FiscalDocument::query()->sole();

    expect($document->number)->toBe('FT 2026/12')
        ->and($document->total_amount)->toBe(12000)
        ->and($document->links->receivable_id)->toBe($receivable->id)
        ->and($entry->fresh()->state)->toBe(IntegrationRecordState::InSync);
});

it('keeps the row and creates nothing when the provider fails during an import', function () {
    $entry = ($this->remoteOnly)('FT 2026/12');
    fakeInvoiceXpress(['GET /invoices/8812.json' => fn () => Http::response('', 503)]);

    Livewire::test(InvoicingSyncPage::class)
        ->callAction(TestAction::make('import')->table($entry))
        ->assertNotified()
        ->assertCanSeeTableRecords([$entry]);

    expect(FiscalDocument::query()->count())->toBe(0);
});

it('looks for the documents of a period, from the first of January until today unless told otherwise', function () {
    fakeInvoiceXpress(['GET /invoices.json' => ($this->list)([($this->remote)(8812, '2026/12', 120.0)])]);

    Livewire::test(InvoicingSyncPage::class)
        ->assertSchemaStateSet(['from' => '2026-01-01', 'until' => '2026-11-12'])
        ->call('importPeriod')
        ->assertHasNoFormErrors()
        ->assertNotified(__('Synchronised'))
        ->assertCountTableRecords(1);
});

it('refuses a period longer than a year, on the date it is about', function () {
    fakeInvoiceXpress();

    Livewire::test(InvoicingSyncPage::class)
        ->fillForm(['from' => '2025-01-01', 'until' => '2026-11-12'])
        ->call('importPeriod')
        ->assertHasFormErrors(['until'])
        ->assertNotNotified(__('Synchronised'));

    Http::assertNothingSent();
});

it('only points to the integrations when no provider is connected', function () {
    $this->connection->delete();
    Invoicing::forget();
    fakeInvoiceXpress();
    DB::enableQueryLog();
    DB::flushQueryLog();

    try {
        Livewire::test(InvoicingSyncPage::class)
            ->assertSee(__('No invoicing provider connected'))
            ->assertSeeHtml(IntegrationsPage::getUrl())
            ->assertDontSee(__('Needing you'))
            ->assertDontSee(__('Import a period'))
            ->assertActionHidden('sync')
            ->assertActionHidden('provider');

        expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $query): bool => str_contains($query, 'integration_records') || str_contains($query, 'integration_operations')))->toBeEmpty();
    } finally {
        DB::disableQueryLog();
    }

    Http::assertNothingSent();
});

it('never shows or counts another account\'s records', function () {
    $mine = ($this->remoteOnly)('FT 2026/12');
    $foreign = CurrentAccount::run(Account::factory()->create(), function (): IntegrationRecord {
        invoicingConnection();

        return ($this->remoteOnly)('FT 2026/99');
    });
    Invoicing::forget();

    Livewire::test(InvoicingSyncPage::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$foreign])
        ->assertCountTableRecords(1)
        ->assertDontSee('FT 2026/99');

    Livewire::test(InvoicingSyncStats::class)->assertSeeInOrder([__('To import'), '1']);
})->group('tenancy');

it('is for the owner: an accountant cannot open it', function () {
    $accountant = User::factory()->create(['email_verified_at' => now()]);
    $this->account->users()->attach($accountant, ['role' => AccountantAccess::ROLE]);
    $this->actingAs($accountant);

    Livewire::test(InvoicingSyncPage::class)->assertForbidden();
})->group('tenancy');

it('lets a read-only account look but not synchronise or import', function () {
    $entry = ($this->remoteOnly)('FT 2026/12');
    $this->account->update(['billing_exempt' => false, 'trial_ends_at' => now()->subDay()]);
    fakeInvoiceXpress();

    Livewire::test(InvoicingSyncPage::class)
        ->assertCanSeeTableRecords([$entry])
        ->assertActionDisabled('sync')
        ->assertTableActionDisabled('import', $entry);

    Http::assertNothingSent();
});

it('lists the rows without a query per row', function () {
    FiscalDocument::factory()->count(3)->create()->each(fn (FiscalDocument $document) => ($this->tied)($document, IntegrationRecordState::Differs, 1));
    ($this->remoteOnly)('FT 2026/12');
    ($this->tied)(draftDocument(), IntegrationRecordState::InSync, remoteState: RemoteDocumentState::Draft);

    DB::enableQueryLog();
    DB::flushQueryLog();

    try {
        Livewire::test(InvoicingSyncPage::class)->assertCountTableRecords(5);

        $queries = collect(DB::getQueryLog())->pluck('query');

        // The rows of the page, then every document they point to at once.
        expect($queries->filter(fn (string $query): bool => str_starts_with($query, 'select * from "integration_records"')))->toHaveCount(1)
            ->and($queries->filter(fn (string $query): bool => str_starts_with($query, 'select * from "fiscal_documents"')))->toHaveCount(1)
            // The latest operations, once.
            ->and($queries->filter(fn (string $query): bool => str_contains($query, 'from "integration_operations"')))->toHaveCount(1);
    } finally {
        DB::disableQueryLog();
    }
})->group('performance');
