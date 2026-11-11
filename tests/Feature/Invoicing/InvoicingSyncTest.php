<?php

use App\Actions\ImportRemoteDocument;
use App\Actions\SyncInvoicingDocuments;
use App\Enums\FiscalDocumentStatus;
use App\Enums\IntegrationOperationStatus;
use App\Enums\IntegrationRecordState;
use App\Exceptions\InvoicingProviderException;
use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\IntegrationOperation;
use App\Models\IntegrationRecord;
use App\Models\Project;
use App\Models\Receivable;
use App\Support\CurrentAccount;
use App\Support\Invoicing\InvoicingRecords;
use App\Support\Invoicing\RemoteDocument;
use App\Support\Invoicing\RemoteDocumentState;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

pest()->group('invoicing', 'fiscal');

/*
 * Keeping the app and the provider in step: what matches, what differs, what only exists at the
 * provider, and bringing those in. Synchronising never changes a document that is already issued.
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
});

it('sorts the provider\'s documents into the ones that match, differ or are missing here', function () {
    $same = FiscalDocument::factory()->create(['number' => 'FT 2026/13', 'net_amount' => 18000, 'vat_amount' => 0, 'total_amount' => 18000]);
    $different = FiscalDocument::factory()->create(['number' => 'FT 2026/9', 'net_amount' => 85000, 'vat_amount' => 0, 'total_amount' => 85000]);
    fakeInvoiceXpress(['GET /invoices.json' => ($this->list)([
        ($this->remote)(8813, '2026/13', 180.0),
        ($this->remote)(8809, '2026/9', 800.0),
        ($this->remote)(8812, '2026/12', 120.0, ['client' => ['name' => 'Oficina Norte', 'fiscal_id' => '509999974']]),
    ])]);

    $counts = app(SyncInvoicingDocuments::class)->handle();

    expect($counts)->toBe(['in_sync' => 1, 'differs' => 1, 'remote_only' => 1, 'issued' => 0])
        ->and(InvoicingRecords::for($same)->state)->toBe(IntegrationRecordState::InSync)
        ->and(InvoicingRecords::for($different)->state)->toBe(IntegrationRecordState::Differs)
        ->and($different->fresh()->total_amount)->toBe(85000)
        ->and($this->connection->fresh()->last_synced_at->toDateTimeString())->toBe('2026-11-12 10:30:00');

    $missing = IntegrationRecord::query()->where('state', IntegrationRecordState::RemoteOnly)->sole();
    expect($missing->record_id)->toBeNull()
        ->and($missing->remote['number'])->toBe('FT 2026/12')
        ->and($missing->remote['total'])->toBe(12000)
        ->and($missing->remote['party_name'])->toBe('Oficina Norte')
        ->and(IntegrationOperation::query()->where('kind', 'synced')->value('summary'))->toContain('1');
});

it('gives the same result when it runs again', function () {
    FiscalDocument::factory()->create(['number' => 'FT 2026/13', 'net_amount' => 18000, 'vat_amount' => 0, 'total_amount' => 18000]);
    fakeInvoiceXpress(['GET /invoices.json' => ($this->list)([($this->remote)(8813, '2026/13', 180.0), ($this->remote)(8812, '2026/12', 120.0)])]);

    app(SyncInvoicingDocuments::class)->handle();
    app(SyncInvoicingDocuments::class)->handle();

    expect(IntegrationRecord::query()->count())->toBe(2)->and(FiscalDocument::query()->count())->toBe(1);
});

it('notices a document cancelled at the provider and leaves the local one for the user to decide', function () {
    $document = FiscalDocument::factory()->create(['number' => 'FT 2026/13', 'net_amount' => 18000, 'vat_amount' => 0, 'total_amount' => 18000]);
    fakeInvoiceXpress(['GET /invoices.json' => ($this->list)([($this->remote)(8813, '2026/13', 180.0, ['status' => 'canceled'])])]);

    app(SyncInvoicingDocuments::class)->handle();

    expect(InvoicingRecords::for($document)->state)->toBe(IntegrationRecordState::Differs)
        ->and($document->fresh()->status)->toBe(FiscalDocumentStatus::Active);
});

it('completes a draft that was issued at the provider itself', function () {
    $draft = draftDocument();
    InvoicingRecords::store($draft, new RemoteDocument('invoices', '9001', $draft->type, RemoteDocumentState::Draft, CarbonImmutable::parse('2026-11-12'), 15000, 0, 15000), IntegrationRecordState::InSync);
    fakeInvoiceXpress([
        'GET /invoices.json' => ($this->list)([]),
        'GET /invoices/9001.json' => invoiceXpressDocument(['status' => 'final', 'inverted_sequence_number' => '2026/15', 'atcud' => 'JFX4K2Q9-15']),
    ]);

    $counts = app(SyncInvoicingDocuments::class)->handle();

    expect($counts['issued'])->toBe(1)
        ->and($draft->fresh()->status)->toBe(FiscalDocumentStatus::Active)
        ->and($draft->fresh()->number)->toBe('FT 2026/15');
});

it('records the failure on the connection when the provider cannot be reached', function () {
    fakeInvoiceXpress(['GET /invoices.json' => fn () => Http::response('', 503)]);

    expect(fn () => app(SyncInvoicingDocuments::class)->handle())->toThrow(InvoicingProviderException::class);

    expect($this->connection->fresh()->last_error)->not->toBeNull()
        ->and($this->connection->fresh()->last_synced_at)->toBeNull()
        ->and(IntegrationOperation::query()->where('kind', 'synced')->value('status'))->toBe(IntegrationOperationStatus::Failed);
});

it('refuses a period longer than a year', function () {
    fakeInvoiceXpress();

    expect(fn () => app(SyncInvoicingDocuments::class)->handle(today()->subDays(400), today()))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('imports a document with its lines and ties it to the receivable the user picks', function () {
    $client = Client::factory()->create(['vat_number' => '509999974']);
    $receivable = Receivable::factory()->for(Project::factory()->for($client), 'billable')->create(['amount' => 12000]);
    $remote = ($this->remote)(8812, '2026/12', 120.0, [
        'atcud' => 'JFX4K2Q9-12', 'client' => ['name' => 'Oficina Norte', 'fiscal_id' => '509999974'],
        'items' => [['description' => 'Avença de outubro', 'unit_price' => '60.0', 'quantity' => '2.0', 'unit' => 'mês', 'discount' => 0.0, 'tax' => ['value' => 0.0]]],
    ]);
    fakeInvoiceXpress(['GET /invoices.json' => ($this->list)([$remote]), 'GET /invoices/8812.json' => ['invoice' => $remote]]);
    app(SyncInvoicingDocuments::class)->handle();
    $entry = IntegrationRecord::query()->sole();

    expect(app(ImportRemoteDocument::class)->suggestions($entry)->pluck('id')->all())->toBe([$receivable->id]);

    $document = app(ImportRemoteDocument::class)->handle($entry, $receivable->id);

    expect($document->number)->toBe('FT 2026/12')
        ->and($document->atcud)->toBe('JFX4K2Q9-12')
        ->and($document->status)->toBe(FiscalDocumentStatus::Active)
        ->and($document->client_id)->toBe($client->id)
        ->and($document->total_amount)->toBe(12000)
        ->and($document->lines)->toHaveCount(1)
        ->and($document->lines[0]->quantity)->toBe(2000)
        ->and($document->lines[0]->net_amount)->toBe(12000)
        ->and($document->links->receivable_id)->toBe($receivable->id)
        ->and($entry->fresh()->state)->toBe(IntegrationRecordState::InSync)
        ->and($entry->fresh()->record_id)->toBe($document->id);

    expect(fn () => app(ImportRemoteDocument::class)->handle($entry->fresh()))->toThrow(ValidationException::class);
    expect(FiscalDocument::query()->count())->toBe(1);
});

it('never ties an imported document to another client\'s receivable', function () {
    $other = Receivable::factory()->for(Project::factory()->for(Client::factory()->create(['vat_number' => '508025338'])), 'billable')->create(['amount' => 12000]);
    $remote = ($this->remote)(8812, '2026/12', 120.0, ['client' => ['name' => 'Oficina Norte', 'fiscal_id' => '509999974']]);
    fakeInvoiceXpress(['GET /invoices.json' => ($this->list)([$remote]), 'GET /invoices/8812.json' => ['invoice' => $remote]]);
    app(SyncInvoicingDocuments::class)->handle();

    expect(fn () => app(ImportRemoteDocument::class)->handle(IntegrationRecord::query()->sole(), $other->id))->toThrow(ValidationException::class);
    expect(FiscalDocument::query()->count())->toBe(0);
});

it('synchronises every connected account from the schedule, each within its own data', function () {
    FiscalDocument::factory()->create(['number' => 'FT 2026/13', 'net_amount' => 18000, 'vat_amount' => 0, 'total_amount' => 18000]);
    $other = Account::factory()->create();
    CurrentAccount::run($other, fn () => FiscalDocument::factory()->create(['number' => 'FT 2026/13', 'net_amount' => 99900, 'vat_amount' => 0, 'total_amount' => 99900]));
    fakeInvoiceXpress(['GET /invoices.json' => ($this->list)([($this->remote)(8813, '2026/13', 180.0)])]);
    auth()->logout();

    $this->artisan('invoicing:sync')->assertSuccessful();

    expect(IntegrationRecord::query()->sole()->state)->toBe(IntegrationRecordState::InSync)
        ->and(CurrentAccount::run($other, fn () => IntegrationRecord::query()->count()))->toBe(0);
});

it('skips an account that turned the automatic synchronisation off', function () {
    $this->connection->update(['settings' => ['auto_sync' => false]]);
    fakeInvoiceXpress();
    auth()->logout();

    $this->artisan('invoicing:sync')->assertSuccessful();

    Http::assertNothingSent();
});
