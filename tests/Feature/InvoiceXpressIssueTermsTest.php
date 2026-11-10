<?php

use App\Support\InvoiceXpressIssueTerms;
use Illuminate\Validation\ValidationException;

it('checks an identified Portuguese customer and computes line amounts to the cent', function () {
    $customer = ['id' => 42, 'name' => 'Cliente de teste', 'fiscal_id' => '508025338', 'country' => 'Portugal'];

    expect(InvoiceXpressIssueTerms::customer($customer, 42, 'PT508025338'))
        ->toBe(['id' => 42, 'name' => 'Cliente de teste', 'nif' => '508025338']);
    expect(InvoiceXpressIssueTerms::totals(10005, 3, 2300))
        ->toBe(['net' => 30015, 'tax' => 6903, 'total' => 36918]);
});

it('rejects an ineligible provider customer', function (array $changes, string $localNif) {
    $customer = array_replace(['id' => 42, 'name' => 'Cliente de teste', 'fiscal_id' => '508025338', 'country' => 'Portugal'], $changes);

    expect(fn () => InvoiceXpressIssueTerms::customer($customer, 42, $localNif))->toThrow(ValidationException::class);
})->with([
    'another record' => [['id' => 43], 'PT508025338'],
    'another fiscal identity' => [['fiscal_id' => '504671968'], 'PT508025338'],
    'non-Portuguese customer' => [['country' => 'Spain'], 'PT508025338'],
    'generic customer' => [['fiscal_id' => '999999990'], '999999990'],
    'unverifiable number' => [['fiscal_id' => '508025339'], '508025339'],
]);

it('requires the approved item and an exact unambiguous tax name and region', function () {
    $item = ['id' => 8, 'name' => 'Consultoria', 'description' => 'Consultoria', 'unit' => 'service', 'unit_price' => '100.0500'];
    $tax = ['id' => 9, 'name' => 'IVA Contiu', 'region' => 'PT', 'code' => 'NOR', 'value' => '23.0'];

    expect(InvoiceXpressIssueTerms::item($item, 8))->toBe([
        'id' => 8, 'name' => 'Consultoria', 'description' => 'Consultoria', 'unit' => 'service', 'unitCents' => 10005,
    ]);
    expect(InvoiceXpressIssueTerms::tax([$tax], 'IVA Contiu', 'PT', 2300))->toBe([
        'id' => 9, 'name' => 'IVA Contiu', 'region' => 'PT', 'basisPoints' => 2300,
    ]);
    expect(fn () => InvoiceXpressIssueTerms::tax([$tax], 'IVA em falta', 'PT', 2300))->toThrow(ValidationException::class)
        ->and(fn () => InvoiceXpressIssueTerms::tax([$tax], 'IVA Contiu', 'PT-MA', 2300))->toThrow(ValidationException::class)
        ->and(fn () => InvoiceXpressIssueTerms::tax([array_replace($tax, ['code' => 'RED'])], 'IVA Contiu', 'PT', 2300))->toThrow(ValidationException::class)
        ->and(fn () => InvoiceXpressIssueTerms::tax([$tax, array_replace($tax, ['id' => 10])], 'IVA Contiu', 'PT', 2300))->toThrow(ValidationException::class)
        ->and(fn () => InvoiceXpressIssueTerms::item(array_replace($item, ['unit_price' => '100.0501']), 8))->toThrow(ValidationException::class);
});

it('requires the registered invoice sequence rather than a generic series or an unregistered code', function () {
    $sequence = ['id' => 19, 'serie' => 'PILOTO', 'current_invoice_sequence_id' => 19, 'current_invoice_validation_code' => 'ABCD1234'];

    expect(InvoiceXpressIssueTerms::sequence($sequence, 19))->toBe(['id' => 19, 'name' => 'PILOTO', 'validationCode' => 'ABCD1234'])
        ->and(fn () => InvoiceXpressIssueTerms::sequence(array_replace($sequence, ['current_invoice_validation_code' => 'N/D']), 19))
        ->toThrow(ValidationException::class)
        ->and(fn () => InvoiceXpressIssueTerms::sequence(array_replace($sequence, ['current_invoice_sequence_id' => 22]), 19))
        ->toThrow(ValidationException::class);
});

it('requires its own registered sequence and code for a fatura-recibo', function () {
    $sequence = ['id' => 19, 'serie' => 'PILOTO', 'current_invoice_sequence_id' => 19, 'current_invoice_validation_code' => 'ABCD1234',
        'current_invoice_receipt_sequence_id' => 25, 'current_invoice_receipt_validation_code' => 'FRCD1234'];

    expect(InvoiceXpressIssueTerms::sequence($sequence, 19, 'invoice_receipt'))
        ->toBe(['id' => 25, 'name' => 'PILOTO', 'validationCode' => 'FRCD1234']);
    expect(fn () => InvoiceXpressIssueTerms::sequence(array_replace($sequence, ['current_invoice_receipt_validation_code' => 'N/D']), 19, 'invoice_receipt'))
        ->toThrow(ValidationException::class);
});

it('rejects extra precision in numeric amounts and rejects overrides in line selections', function () {
    expect(InvoiceXpressIssueTerms::hundredths(100.05000000000001))->toBeNull()
        ->and(InvoiceXpressIssueTerms::hundredths(100.05))->toBe(10005);
    expect(fn () => InvoiceXpressIssueTerms::lineSelections(8, 1, [
        ['itemId' => 12, 'quantity' => 1, 'unit_price' => 0.01],
    ], [8, 12], 10))->toThrow(ValidationException::class);
});
