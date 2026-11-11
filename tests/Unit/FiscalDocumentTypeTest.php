<?php

use App\Enums\FiscalDocumentType;

/*
 * Which documents count as billing, which are paid on issue and which the "New document" menu
 * offers. Every screen and report asks the enum, so the sets are pinned here.
 */

it('counts as billing only what charges or corrects a sale', function () {
    expect(FiscalDocumentType::billing())->toBe([
        FiscalDocumentType::Invoice, FiscalDocumentType::InvoiceReceipt, FiscalDocumentType::SimplifiedInvoice,
        FiscalDocumentType::CreditNote, FiscalDocumentType::DebitNote,
    ]);

    foreach ([FiscalDocumentType::Quote, FiscalDocumentType::ProForma, FiscalDocumentType::TransportGuide, FiscalDocumentType::Receipt] as $type) {
        expect($type->countsAsBilling())->toBeFalse();
    }
});

it('offers transport guides only to accounts that sell or move goods', function () {
    expect(FiscalDocumentType::creatable(sellsGoods: false))->not->toContain(FiscalDocumentType::TransportGuide)
        ->and(FiscalDocumentType::creatable(sellsGoods: true))->toContain(FiscalDocumentType::TransportGuide);
});

it('never offers a document that needs an original in the new document menu', function () {
    foreach (FiscalDocumentType::creatable(sellsGoods: true) as $type) {
        expect($type->requiresOriginal())->toBeFalse();
    }
});

it('knows which documents are paid when issued', function () {
    expect(array_values(array_filter(FiscalDocumentType::cases(), fn (FiscalDocumentType $type): bool => $type->settlesOnIssue())))
        ->toBe([FiscalDocumentType::InvoiceReceipt, FiscalDocumentType::SimplifiedInvoice]);
});

it('gives a due or validity date only to what is still to be paid or accepted', function () {
    expect(array_values(array_filter(FiscalDocumentType::cases(), fn (FiscalDocumentType $type): bool => $type->hasTerm())))->toBe([
        FiscalDocumentType::Invoice, FiscalDocumentType::DebitNote, FiscalDocumentType::Quote, FiscalDocumentType::ProForma,
    ]);
});

it('gives every type its own number prefix', function () {
    $prefixes = array_map(fn (FiscalDocumentType $type): string => $type->prefix(), FiscalDocumentType::cases());

    expect(array_unique($prefixes))->toHaveCount(count(FiscalDocumentType::cases()));
});
