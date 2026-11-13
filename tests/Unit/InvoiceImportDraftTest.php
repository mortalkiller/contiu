<?php

use App\Support\InvoiceImportDraft;

/** What a reading gives, as InvoiceData names it: an invoice of 100,00 € + 23%. */
function invoiceReading(array $fields = []): array
{
    return $fields + [
        'document_type' => 'FT', 'invoice_number' => 'FT 1/1', 'issue_date' => '2026-11-10',
        'supplier_name' => 'Fornecedor', 'supplier_nif' => '508025338',
        'net_amount' => '100.00', 'total_tax' => '23.00', 'gross_total' => '123.00',
    ];
}

it('turns the amounts into cents and finds the VAT rate that fits them', function () {
    expect(InvoiceImportDraft::fromReading(invoiceReading(), []))->toMatchArray([
        'type' => 'invoice', 'number' => 'FT 1/1', 'date' => '2026-11-10', 'supplier_nif' => '508025338',
        'net' => 10000, 'vat' => 2300, 'total' => 12300,
        'rates' => [['rate' => '0.2300', 'net' => 10000, 'vat' => 2300]],
        'paid' => false, 'exact' => [],
    ]);
});

it('works out the amount that was not read from the other two', function (array $fields, int $net, int $vat, int $total) {
    $draft = InvoiceImportDraft::fromReading(invoiceReading($fields), []);

    expect([$draft['net'], $draft['vat'], $draft['total']])->toBe([$net, $vat, $total]);
})->with([
    'no base' => [['net_amount' => null], 10000, 2300, 12300],
    'no VAT' => [['total_tax' => null], 10000, 2300, 12300],
    'no total' => [['gross_total' => null], 10000, 2300, 12300],
    'a comma for the decimals' => [['net_amount' => '10,00', 'total_tax' => '0,60', 'gross_total' => '10,60'], 1000, 60, 1060],
]);

it('keeps one entry per VAT rate when the table adds up to the totals', function () {
    $draft = InvoiceImportDraft::fromReading(invoiceReading([
        'net_amount' => '20.77', 'total_tax' => '2.68', 'gross_total' => '23.45',
        'vat' => [
            ['category' => 'standard', 'rate' => '23', 'taxable_amount' => '6.77', 'vat_amount' => '1.56'],
            ['category' => 'reduced', 'rate' => '6', 'taxable_amount' => '10.00', 'vat_amount' => '0.60'],
            ['category' => 'intermediate', 'rate' => '13.00', 'taxable_amount' => '4.00', 'vat_amount' => '0.52'],
        ],
    ]), []);

    expect($draft['rates'])->toBe([
        ['rate' => '0.0600', 'net' => 1000, 'vat' => 60],
        ['rate' => '0.1300', 'net' => 400, 'vat' => 52],
        ['rate' => '0.2300', 'net' => 677, 'vat' => 156],
    ]);
});

it('trusts the totals over a VAT table that does not add up to them', function () {
    $draft = InvoiceImportDraft::fromReading(invoiceReading([
        'vat' => [
            ['category' => 'standard', 'rate' => '23', 'taxable_amount' => '50.00', 'vat_amount' => '11.50'],
            ['category' => 'reduced', 'rate' => '6', 'taxable_amount' => '10.00', 'vat_amount' => '0.60'],
        ],
    ]), []);

    expect($draft['rates'])->toBe([['rate' => '0.2300', 'net' => 10000, 'vat' => 2300]]);
});

it('names the kind of document as the app does, and refuses what is not an invoice', function (string $read, ?string $type) {
    expect(InvoiceImportDraft::fromReading(invoiceReading(['document_type' => $read]), [])['type'] ?? null)->toBe($type);
})->with([
    ['FT', 'invoice'],
    ['fr', 'invoice_receipt'],
    ['FS', 'simplified_invoice'],
    ['Fatura-Recibo', 'invoice_receipt'],
    ['Fatura simplificada', 'simplified_invoice'],
    ['Factura', 'invoice'],
    ['Documento', null],
]);

it('does not read a credit note, a receipt or a quote as an invoice', function (string $read) {
    expect(InvoiceImportDraft::fromReading(invoiceReading(['document_type' => $read]), []))->toBeNull();
})->with(['NC', 'Nota de crédito', 'Recibo', 'Orçamento', 'Fatura pró-forma', 'Guia de transporte']);

it('takes a fatura-recibo as paid and an invoice as paid only when it says so', function () {
    expect(InvoiceImportDraft::fromReading(invoiceReading(['document_type' => 'FR']), [])['paid'])->toBeTrue()
        ->and(InvoiceImportDraft::fromReading(invoiceReading(['paid_amount' => '123.00']), [])['paid'])->toBeTrue()
        ->and(InvoiceImportDraft::fromReading(invoiceReading(['paid_amount' => '50.00']), [])['paid'])->toBeFalse();
});

it('drops a tax ID that is not a valid Portuguese one and marks what the QR code gave as exact', function () {
    $draft = InvoiceImportDraft::fromReading(
        invoiceReading(['supplier_nif' => '123456780', 'customer_nif' => 'PT 508 025 338']),
        ['invoice_number' => 'FT 1/1', 'gross_total' => '123.00', 'atcud' => null],
    );

    expect($draft)->supplier_nif->toBeNull()->customer_nif->toBe('508025338')->exact->toBe(['number', 'total']);
});
