<?php

use App\Enums\IntegrationCategory;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Models\IntegrationConnection;
use App\Support\FiscalDocumentTotals;
use App\Support\Invoicing\Invoicing;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** The account's connection to the invoicing provider, as the "connect" screen leaves it. */
function invoicingConnection(array $settings = []): IntegrationConnection
{
    $connection = IntegrationConnection::factory()->create([
        'category' => IntegrationCategory::Invoicing,
        'credentials' => ['subdomain' => 'contiu-test', 'api_key' => 'secret-key-123'],
        'settings' => $settings,
    ]);
    Invoicing::forget();

    return $connection;
}

/**
 * A draft invoice with lines, its amounts worked out the way the app does.
 *
 * @param  list<array<string, mixed>>  $lines
 */
function draftDocument(array $attributes = [], array $lines = [['description' => 'Desenho do site', 'quantity' => 1000, 'unit_price' => 15000]]): FiscalDocument
{
    $totals = FiscalDocumentTotals::of($lines, $attributes['withholding_basis_points'] ?? 0);
    $client = Client::factory()->create(['name' => 'Estúdio Atlântico, Lda.', 'vat_number' => '508025338']);

    $document = FiscalDocument::factory()->draft()->create([
        'client_id' => $client->id,
        'party_name' => $client->name,
        'party_tax_id' => $client->vat_number,
        'net_amount' => $totals->net,
        'vat_amount' => $totals->vat,
        'total_amount' => $totals->total,
        'discount_amount' => $totals->discount,
        'withholding_amount' => $totals->withholding,
        ...$attributes,
    ]);

    foreach ($lines as $index => $line) {
        FiscalDocumentLine::factory()->for($document)->create([
            'sort' => $index,
            'unit' => 'un',
            ...$line,
            'net_amount' => $totals->lines[$index]['net'],
            'vat_amount' => $totals->lines[$index]['vat'],
            'total_amount' => $totals->lines[$index]['total'],
        ]);
    }

    return $document->load('lines');
}

/** A document as InvoiceXpress returns it. */
function invoiceXpressDocument(array $overrides = [], string $key = 'invoice'): array
{
    return [$key => [
        'id' => 9001,
        'status' => 'draft',
        'type' => 'Invoice',
        'date' => '12/11/2026',
        'due_date' => '12/12/2026',
        'sequence_number' => 'rascunho',
        'before_taxes' => 150.0,
        'taxes' => 0.0,
        'total' => 150.0,
        'retention' => '0.0',
        'client' => ['id' => 77, 'name' => 'Estúdio Atlântico, Lda.', 'fiscal_id' => '508025338'],
        'items' => [['name' => 'Desenho do site', 'description' => 'Desenho do site', 'unit_price' => '150.0', 'quantity' => '1.0', 'unit' => 'un', 'discount' => 0.0, 'tax' => ['name' => 'Isento', 'value' => 0.0]]],
        ...$overrides,
    ]];
}

/**
 * Stands in for InvoiceXpress: answers by path and refuses anything sent anywhere else.
 *
 * @param  array<string, Closure(Request): mixed|array<string, mixed>>  $routes  path (with the method before it, e.g. "POST /invoices.json") to its answer
 */
function fakeInvoiceXpress(array $routes = []): void
{
    $routes += [
        'GET /sequences.json' => ['sequences' => [[
            'id' => 5, 'serie' => '2026', 'default_sequence' => 1,
            'current_invoice_sequence_id' => 5, 'current_invoice_number' => 14,
            'current_invoice_receipt_sequence_id' => 6, 'current_invoice_receipt_number' => 14,
            'current_simplified_invoice_sequence_id' => 7, 'current_simplified_invoice_number' => 0,
            'current_credit_note_sequence_id' => 8, 'current_credit_note_number' => 2,
            'current_debit_note_sequence_id' => 9, 'current_debit_note_number' => 0,
            'current_receipt_sequence_id' => 10, 'current_receipt_number' => 4,
            'current_quote_sequence_id' => 11, 'current_quote_number' => 3,
            'current_proforma_sequence_id' => 12, 'current_proforma_number' => 0,
            'current_transport_sequence_id' => 13, 'current_transport_number' => 0,
        ]]],
        'GET /taxes.json' => ['taxes' => [
            ['id' => 1, 'name' => 'Isento', 'value' => 0.0, 'region' => ''],
            ['id' => 2, 'name' => 'IVA23', 'value' => 23.0, 'region' => 'PT'],
            ['id' => 3, 'name' => 'IVA6', 'value' => 6.0, 'region' => 'PT'],
        ]],
    ];

    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($routes) {
        $url = parse_url($request->url());
        abort_unless(($url['host'] ?? null) === 'contiu-test.app.invoicexpress.com', 500, 'Unexpected host');
        $route = $request->method().' '.$url['path'];
        abort_unless(array_key_exists($route, $routes), 500, "No fake for {$route}");
        $answer = $routes[$route];

        return match (true) {
            $answer instanceof Closure => $answer($request),
            $answer === [] => Http::response('', 200),
            default => Http::response($answer),
        };
    });
}
