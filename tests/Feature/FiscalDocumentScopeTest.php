<?php

use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Models\FiscalDocument;
use App\Support\DocumentedTurnover;
use App\Support\VatPeriodReview;
use Illuminate\Support\Carbon;

pest()->group('fiscal');

/*
 * What counts as billed. A draft is not a document yet, and a quote, a pro forma or a transport
 * guide never is: none of them may reach the turnover or the VAT of the period.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-12'));
});

it('counts a simplified invoice as billed, like any other invoice', function () {
    FiscalDocument::factory()->create(['type' => FiscalDocumentType::SimplifiedInvoice, 'net_amount' => 6300, 'vat_amount' => 0, 'total_amount' => 6300]);

    expect(DocumentedTurnover::forYear(2026)->billedNet)->toBe(6300);
});

it('leaves drafts out of the turnover and the VAT of the period', function () {
    FiscalDocument::factory()->draft()->create();

    expect(DocumentedTurnover::forYear(2026)->billedNet)->toBeNull()
        ->and(DocumentedTurnover::forYear(2026)->documentCount)->toBe(0)
        ->and(VatPeriodReview::forPeriod(2026, 11)->documents)->toBeEmpty();
});

it('never counts a document that is not an invoice or a note', function (FiscalDocumentType $type) {
    FiscalDocument::factory()->create(['type' => $type]);

    expect(DocumentedTurnover::forYear(2026)->documentCount)->toBe(0)
        ->and(VatPeriodReview::forPeriod(2026, 11)->documents)->toBeEmpty();
})->with([
    'quote' => [FiscalDocumentType::Quote],
    'pro forma' => [FiscalDocumentType::ProForma],
    'transport guide' => [FiscalDocumentType::TransportGuide],
]);

it('keeps the status of an issued document when nothing says otherwise', function () {
    expect(FiscalDocument::factory()->create()->status)->toBe(FiscalDocumentStatus::Active);
});
