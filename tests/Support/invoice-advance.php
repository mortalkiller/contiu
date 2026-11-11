<?php

use App\Actions\ManageCustomerAdvance;
use App\Enums\FiscalDocumentType;
use App\Enums\PaymentMethod;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\Project;
use App\Models\Receivable;
use App\Support\WithholdingReview;

/** A charge of a Portuguese business client, on a project taxed at 23%, as the owner. */
function advanceReceivable(): Receivable
{
    actingAsAdmin();
    test()->travelTo(now()->setDate(2026, 11, 10));
    $client = Client::factory()->create([
        'fiscal_country_code' => 'PT', 'vat_number' => 'PT508025338', 'fiscal_party_type' => 'business',
    ]);
    $project = Project::factory()->for($client)->create(['vat_rate' => '0.2300']);

    return Receivable::factory()->for($project, 'billable')->create(['amount' => 36918, 'due_on' => '2026-12-10']);
}

/** An advance the client paid before the invoice, applied to the charge, with or without its own document. */
function allocatedAdvance(Receivable $receivable, int $net = 10005, int $vat = 2301, bool $documented = true, int $retained = 0): ?FiscalDocument
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
