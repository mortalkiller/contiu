<?php

use App\Enums\FiscalDocumentType;
use App\Models\FiscalDocument;
use App\Models\Payment;
use App\Support\InvoiceXpressIssueAdvance;
use Illuminate\Validation\ValidationException;

pest()->group('fiscal', 'integration');

it('plans a credit note for the original and an invoice for the full service without recording new cash', function (int $retained) {
    $charge = invoiceXpressIssueReceivable();
    $advance = invoiceXpressAllocatedAdvance($charge, retained: $retained);
    $payment = $charge->payments()->sole();
    $service = ['net' => 30015, 'tax' => 6903, 'total' => 36918];

    $plan = InvoiceXpressIssueAdvance::plan($charge, $payment, $service, 2300, '2026-11-10', 2300);

    expect($plan['creditNote'])->toBe(['originalDocumentId' => $advance->id, 'totals' => ['net' => 10005, 'tax' => 2301, 'total' => 12306]]);
    expect($plan['finalInvoice']['totals'])->toBe($service);
    expect($plan['reference'])->toBe($advance->number);
    expect($plan['date'])->toBe('01/11/2026');
    expect($plan['received'])->toBe(['cash' => 12306 - $retained, 'retained' => $retained]);
    expect($plan['balance'])->toBe(['net' => 20010, 'tax' => 4602, 'total' => 24612]);
    expect($plan['remainingWithholding'])->toBe(['basisPoints' => 2300, 'gross' => 24612, 'retained' => 4602, 'cash' => 20010]);
    expect(InvoiceXpressIssueAdvance::plan($charge, $payment, $service, 2300, '2026-11-10', 2300))->toBe($plan);
    expect(FiscalDocument::count())->toBe(1)->and(Payment::count())->toBe(1);
})->with(['cash only' => 0, 'cash and confirmed withholding' => 3000]);

it('preserves separate VAT rounding of the full invoice and credit note', function () {
    $charge = invoiceXpressIssueReceivable();
    $charge->update(['amount' => 4]);
    invoiceXpressAllocatedAdvance($charge, net: 2, vat: 0);

    $plan = InvoiceXpressIssueAdvance::plan($charge, $charge->payments()->sole(),
        ['net' => 3, 'tax' => 1, 'total' => 4], 2300, '2026-11-10');

    expect($plan['balance'])->toBe(['net' => 1, 'tax' => 1, 'total' => 2]);
});

it('does not estimate further withholding when a retained advance already covers the full invoice', function () {
    $charge = invoiceXpressIssueReceivable();
    $charge->update(['amount' => 12306]);
    invoiceXpressAllocatedAdvance($charge, retained: 3000);

    $plan = InvoiceXpressIssueAdvance::plan($charge, $charge->payments()->sole(),
        ['net' => 10005, 'tax' => 2301, 'total' => 12306], 2300, '2026-11-10', 2300);

    expect($plan['balance'])->toBe(['net' => 0, 'tax' => 0, 'total' => 0]);
    expect($plan['received']['retained'])->toBe(3000);
    expect($plan['remainingWithholding'])->toBeNull();
});

it('rejects advance plans when a correction or final invoice is already recorded', function (bool $hasCorrection) {
    $charge = invoiceXpressIssueReceivable();
    $advance = invoiceXpressAllocatedAdvance($charge);
    $document = FiscalDocument::factory()->create([
        'type' => $hasCorrection ? FiscalDocumentType::CreditNote : FiscalDocumentType::Invoice,
    ]);
    $document->links()->create($hasCorrection ? ['related_fiscal_document_id' => $advance->id] : ['receivable_id' => $charge->id]);

    expect(fn () => InvoiceXpressIssueAdvance::plan($charge, $charge->payments()->sole(),
        ['net' => 30015, 'tax' => 6903, 'total' => 36918], 2300, '2026-11-10'))->toThrow(ValidationException::class);
})->with(['credit note already linked to the advance' => true, 'invoice already linked to the receivable' => false]);

it('accepts a separate cash receipt only when it matches the original and payment', function (bool $matches) {
    $charge = invoiceXpressIssueReceivable();
    $advance = invoiceXpressAllocatedAdvance($charge);
    $advance->update(['type' => FiscalDocumentType::Invoice, 'number' => 'FT SINAL/1']);
    $receipt = FiscalDocument::factory()->create([
        ...$advance->only(['document_date', 'net_amount', 'vat_amount', 'total_amount', 'party_tax_id']),
        'type' => FiscalDocumentType::Receipt,
        ...($matches ? [] : ['total_amount' => $advance->total_amount - 1]),
    ]);
    $receipt->links()->create(['payment_id' => $advance->links->payment_id, 'related_fiscal_document_id' => $advance->id]);

    $plan = fn (): array => InvoiceXpressIssueAdvance::plan($charge, $charge->payments()->sole(),
        ['net' => 30015, 'tax' => 6903, 'total' => 36918], 2300, '2026-11-10');

    if ($matches) {
        expect($plan()['received'])->toBe(['cash' => 12306, 'retained' => 0]);
    } else {
        expect($plan)->toThrow(ValidationException::class);
    }
})->with(['verified receipt' => true, 'mismatched receipt' => false]);
