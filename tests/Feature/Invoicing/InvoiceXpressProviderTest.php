<?php

use App\Enums\FiscalDocumentType;
use App\Exceptions\AccountIsReadOnly;
use App\Exceptions\InvoicingProviderException;
use App\Models\User;
use App\Support\AccountantAccess;
use App\Support\Invoicing\InvoiceXpress\InvoiceXpressProvider;
use App\Support\Invoicing\InvoiceXpress\Mapper;
use App\Support\Invoicing\Invoicing;
use App\Support\Invoicing\RemoteDocumentState;
use App\Support\Invoicing\RemoteReferences;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

pest()->group('invoicing');

/*
 * InvoiceXpress as the invoicing provider: what the app sends it and what it understands of the
 * answers. InvoiceXpress itself is faked, and anything sent to another host fails the test.
 */

beforeEach(function () {
    $this->actingAs(ownerOf($this->account));
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->account);
    $this->connection = invoicingConnection();
});

it('reads the series with the next number of each document type, and the taxes, asking only once', function () {
    fakeInvoiceXpress();

    $catalog = Invoicing::provider()->catalog();
    Invoicing::provider()->catalog();

    expect($catalog->nextNumber(FiscalDocumentType::Invoice))->toBe('FT 2026/15')
        ->and($catalog->nextNumber(FiscalDocumentType::CreditNote))->toBe('NC 2026/3')
        ->and($catalog->nextNumber(FiscalDocumentType::TransportGuide))->toBe('GT 2026/1')
        ->and($catalog->taxName(2300, 'PT'))->toBe('IVA23')
        ->and($catalog->taxName(0))->toBe('Isento')
        ->and($catalog->taxName(1300))->toBeNull();

    Http::assertSentCount(2);
});

it('reads the catalogue back from a cache store that serialises what it keeps', function () {
    config(['cache.default' => 'database']);
    fakeInvoiceXpress();

    (new InvoiceXpressProvider($this->connection))->catalog();
    $catalog = (new InvoiceXpressProvider($this->connection))->catalog();

    expect($catalog->nextNumber(FiscalDocumentType::Invoice))->toBe('FT 2026/15')
        ->and($catalog->taxName(2300, 'PT'))->toBe('IVA23');

    Http::assertSentCount(2);
});

it('creates the draft with the lines, the client, the series and the withholding the document has', function () {
    $document = draftDocument(
        ['withholding_basis_points' => 2300, 'vat_exemption_reason' => 'M10', 'client_reference' => 'EA-2026-118', 'due_date' => '2026-12-12', 'document_date' => '2026-11-12'],
        [
            ['description' => 'Desenho do site', 'quantity' => 1000, 'unit_price' => 15000],
            ['description' => 'Hora de desenvolvimento', 'quantity' => 1500, 'unit_price' => 4500, 'discount_basis_points' => 1000],
        ],
    );
    fakeInvoiceXpress(['POST /invoices.json' => invoiceXpressDocument(['before_taxes' => 210.75, 'total' => 162.28, 'retention' => '23.0'])]);

    $remote = Invoicing::provider()->saveDraft($document, new RemoteReferences(client: '77'));

    expect($remote->state)->toBe(RemoteDocumentState::Draft)
        ->and($remote->remoteType)->toBe('invoices')
        ->and($remote->remoteId)->toBe('9001')
        ->and($remote->number)->toBeNull()
        ->and($remote->matches($document->net_amount, $document->vat_amount, $document->total_amount))->toBeTrue()
        ->and($remote->withholding)->toBe($document->withholding_amount);

    Http::assertSent(function (Request $request): bool {
        if ($request->method() !== 'POST') {
            return false;
        }

        $invoice = $request->data()['invoice'];

        return $invoice['date'] === '12/11/2026'
            && $invoice['due_date'] === '12/12/2026'
            && $invoice['reference'] === 'EA-2026-118'
            && $invoice['client'] === ['id' => 77]
            && $invoice['sequence_id'] === '5'
            && $invoice['retention'] === '23.00'
            && $invoice['tax_exemption_reason'] === 'M10'
            && $invoice['items'][1] === [
                'name' => 'Hora de desenvolvimento', 'description' => 'Hora de desenvolvimento', 'unit_price' => '45.00',
                'quantity' => '1.500', 'unit' => 'unit', 'discount' => '10.00', 'tax' => ['name' => 'Isento'],
            ]
            && strlen($request->data()['proprietary_uid']) === 32;
    });
});

it('sends a unit as one of those InvoiceXpress accepts', function (string $written, string $sent) {
    expect(Mapper::unit($written))->toBe($sent);
})->with([
    'the default unit' => ['un', 'unit'],
    'an hour' => ['h', 'hour'],
    'a month, with its accent' => ['Mês', 'month'],
    'one InvoiceXpress already names' => ['service', 'service'],
    'a unit it does not know' => ['cx', 'other'],
    'nothing written' => ['', 'other'],
]);

it('updates the draft InvoiceXpress already has instead of creating another', function () {
    fakeInvoiceXpress([
        'PUT /invoices/9001.json' => [],
        'GET /invoices/9001.json' => invoiceXpressDocument(),
    ]);

    $remote = Invoicing::provider()->saveDraft(draftDocument(), new RemoteReferences(document: '9001', client: '77'));

    expect($remote->remoteId)->toBe('9001');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

it('issues the draft and brings back the number, the series and the ATCUD', function () {
    fakeInvoiceXpress([
        'PUT /invoices/9001/change-state.json' => [],
        'GET /invoices/9001.json' => invoiceXpressDocument(['status' => 'final', 'inverted_sequence_number' => '2026/15', 'atcud' => 'JFX4K2Q9-15']),
    ]);

    $remote = Invoicing::provider()->issue(draftDocument(), '9001');

    expect($remote->isIssued())->toBeTrue()
        ->and($remote->number)->toBe('FT 2026/15')
        ->and($remote->series)->toBe('2026')
        ->and($remote->atcud)->toBe('JFX4K2Q9-15');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && $request->data() === ['invoice' => ['state' => 'finalized']]);
});

it('names what InvoiceXpress refused without ever showing the key', function () {
    fakeInvoiceXpress(['POST /invoices.json' => fn () => Http::response(['errors' => [['error' => "O cliente não tem <b>NIF</b>\n"]]], 422)]);

    try {
        Invoicing::provider()->saveDraft(draftDocument(), new RemoteReferences(client: '77'));
        $this->fail('The provider accepted a refused document.');
    } catch (InvoicingProviderException $exception) {
        expect($exception->getMessage())->toContain('O cliente não tem NIF')
            ->not->toContain('secret-key-123')->not->toContain('<b>')
            ->and($exception->status)->toBe(422);
    }
});

it('never serialises the credentials of the connection', function () {
    expect($this->connection->toArray())->not->toHaveKey('credentials')
        ->and(json_encode($this->connection))->not->toContain('secret-key-123')
        ->and($this->connection->getRawOriginal('credentials'))->not->toContain('secret-key-123');
});

it('refuses to write for someone who can only read the account', function () {
    fakeInvoiceXpress();
    $document = draftDocument();
    $accountant = User::factory()->create(['email_verified_at' => now()]);
    $this->account->users()->attach($accountant, ['role' => AccountantAccess::ROLE]);
    $this->actingAs($accountant);

    expect(fn () => Invoicing::provider()->saveDraft($document, new RemoteReferences(client: '77')))->toThrow(AccountIsReadOnly::class);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
});

it('accepts only an account name and a plain key as credentials', function (array $input, string $field) {
    try {
        InvoiceXpressProvider::credentials($input);
        $this->fail('The credentials were accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
})->with([
    'a full address' => [['subdomain' => 'evil.example.com/path', 'api_key' => 'k'], 'subdomain'],
    'another host' => [['subdomain' => 'a.b', 'api_key' => 'k'], 'subdomain'],
    'no key' => [['subdomain' => 'contiu', 'api_key' => ' '], 'api_key'],
    'a key with a line break' => [['subdomain' => 'contiu', 'api_key' => "abc\ndef"], 'api_key'],
]);

it('normalises the account name', function () {
    expect(InvoiceXpressProvider::credentials(['subdomain' => ' Contiu-Demo ', 'api_key' => ' key ']))
        ->toBe(['subdomain' => 'contiu-demo', 'api_key' => 'key']);
});

it('only follows a PDF link to InvoiceXpress itself', function () {
    Http::preventStrayRequests();
    Http::fake(['contiu-test.app.invoicexpress.com/api/pdf/9001.json*' => Http::response(
        ['output' => ['pdfUrl' => 'https://evil.example/file.pdf']], 200, ['Content-Type' => 'application/json'],
    )]);

    expect(fn () => Invoicing::provider()->pdf('invoices', '9001'))->toThrow(InvoicingProviderException::class);
    Http::assertSentCount(1);
});
