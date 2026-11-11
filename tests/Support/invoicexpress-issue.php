<?php

use App\Actions\ManageCustomerAdvance;
use App\Enums\FiscalDocumentType;
use App\Enums\PaymentMethod;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\InvoiceXpressConfig;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Setting;
use App\Support\WithholdingReview;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function invoiceXpressIssueReceivable(): Receivable
{
    $owner = actingAsAdmin();
    config(['services.invoicexpress.catalog_safe_accounts' => ['pilot']]);
    test()->travelTo(now()->setDate(2026, 11, 10));
    InvoiceXpressConfig::store('pilot', 'fake-test-credential');
    Setting::create(['key' => 'invoice_xpress_issue', 'value' => [
        'provider' => 'invoicexpress', 'user_id' => $owner->id, 'subdomain' => 'pilot',
        'item_ids' => [8], 'tax_name' => 'IVA Contiu', 'sequence_id' => 19,
    ]]);
    $client = Client::factory()->create([
        'fiscal_country_code' => 'PT', 'vat_number' => 'PT508025338', 'fiscal_party_type' => 'business',
    ]);
    $project = Project::factory()->for($client)->create(['vat_rate' => '0.2300']);

    $receivable = Receivable::factory()->for($project, 'billable')->create(['amount' => 36918, 'due_on' => '2026-12-10']);
    test()->invoiceXpressIssueReference = "Contiu #{$receivable->id}";

    return $receivable;
}

function invoiceXpressIssueResponse(string $status = 'draft', array $overrides = []): array
{
    return ['invoice' => array_replace([
        'id' => 65, 'status' => $status, 'type' => 'Invoice', 'currency' => 'Euro', 'sequence_id' => 19,
        'date' => '10/11/2026', 'due_date' => '10/12/2026', 'reference' => test()->invoiceXpressIssueReference,
        'client' => ['id' => 42, 'name' => 'Cliente Teste', 'country' => 'Portugal', 'fiscal_id' => '508025338'],
        'before_taxes' => 300.15, 'taxes' => 69.03, 'total' => 369.18, 'discount' => 0, 'retention' => null,
        'items' => [[
            'name' => 'Consultoria', 'description' => 'Serviço', 'unit' => 'service', 'unit_price' => '100.0500',
            'quantity' => '3.0', 'discount' => 0, 'subtotal' => 300.15, 'tax_amount' => 69.03, 'total' => 369.18,
            'tax' => ['id' => 9, 'name' => 'IVA Contiu', 'value' => 23],
        ]],
        ...($status === 'final' ? ['inverted_sequence_number' => 'PILOTO/7', 'atcud' => 'ABCD1234-7'] : []),
    ], $overrides)];
}

function invoiceXpressIssueFake(Closure $invoice, string $itemPrice = '100.0500'): void
{
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($invoice, $itemPrice) {
        return match (parse_url($request->url(), PHP_URL_PATH)) {
            '/clients/42.json' => Http::response(['client' => ['id' => 42, 'name' => 'Cliente Teste', 'country' => 'Portugal', 'fiscal_id' => '508025338']]),
            '/items/8.json' => Http::response(['item' => ['id' => 8, 'name' => 'Consultoria', 'description' => 'Serviço', 'unit' => 'service', 'unit_price' => $itemPrice,
                'tax' => ['id' => 9, 'name' => 'IVA Contiu', 'value' => 23]]]),
            '/taxes.json' => Http::response(['taxes' => [['id' => 9, 'name' => 'IVA Contiu', 'region' => 'PT', 'code' => 'NOR', 'value' => '23.0']]]),
            '/items/12.json' => Http::response(['item' => ['id' => 12, 'name' => 'Suporte', 'description' => 'Apoio', 'unit' => 'hour', 'unit_price' => '100.05',
                'tax' => ['id' => 9, 'name' => 'IVA Contiu', 'value' => 23]]]),
            '/sequences/19.json' => Http::response(['sequence' => ['id' => 19, 'serie' => 'PILOTO', 'current_invoice_sequence_id' => 19, 'current_invoice_validation_code' => 'ABCD1234',
                'current_invoice_receipt_sequence_id' => 25, 'current_invoice_receipt_validation_code' => 'FRCD1234',
                'current_simplified_invoice_sequence_id' => 22, 'current_simplified_invoice_validation_code' => 'FSCD1234']]),
            default => $invoice($request),
        };
    });
}

function invoiceXpressIssueReceiptResponse(string $status): array
{
    return ['invoice_receipt' => invoiceXpressIssueResponse('draft', [
        'status' => $status, 'type' => 'InvoiceReceipt', 'sequence_id' => 25,
        'due_date' => '10/11/2026', 'inverted_sequence_number' => 'PILOTO/7', 'atcud' => 'FRCD1234-7',
    ])['invoice']];
}

function invoiceXpressAllocatedAdvance(Receivable $receivable, int $net = 10005, int $vat = 2301, bool $documented = true, int $retained = 0): ?FiscalDocument
{
    $advances = app(ManageCustomerAdvance::class);
    $payment = $advances->record($receivable->client->id, $net + $vat - $retained, '2026-11-01', PaymentMethod::Transfer);

    if (! $documented) {
        $advances->apply($payment->id, $receivable->id, '2026-11-05');

        return null;
    }

    $advance = FiscalDocument::factory()->create([
        'type' => FiscalDocumentType::InvoiceReceipt, 'number' => 'FR SINAL/1', 'document_date' => '2026-11-01',
        'net_amount' => $net, 'vat_amount' => $vat, 'total_amount' => $net + $vat,
        'party_tax_id' => '508025338',
    ]);
    $advance->links()->create(['payment_id' => $payment->id]);
    if ($retained > 0) {
        WithholdingReview::record($advance->id, $retained);
    }
    $advances->apply($payment->id, $receivable->id, '2026-11-05');

    return $advance;
}
