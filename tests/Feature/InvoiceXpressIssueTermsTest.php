<?php

use App\Support\InvoiceXpressIssueTerms;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

pest()->group('integration', 'invoicexpress');

it('checks the 25-euro threshold after rounding the withholding to integer cents', function () {
    expect(fn () => InvoiceXpressIssueTerms::withholding(2300, 10865, 13364))->toThrow(ValidationException::class)
        ->and(InvoiceXpressIssueTerms::withholding(2300, 10870, 13370))
        ->toBe(['basisPoints' => 2300, 'gross' => 13370, 'retained' => 2500, 'cash' => 10870]);
});

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

it('preserves line selections without discounts and carries each nonzero discount independently', function () {
    expect(InvoiceXpressIssueTerms::lineSelections(8, 3, [
        ['itemId' => 12, 'quantity' => '2', 'discountBasisPoints' => '1250'],
        ['itemId' => 8, 'quantity' => 1, 'discountBasisPoints' => 0],
        ['itemId' => 12, 'quantity' => 1],
    ], [8, 12], 10, discountBasisPoints: 500))->toBe([
        ['itemId' => 8, 'quantity' => 3, 'discountBasisPoints' => 500],
        ['itemId' => 12, 'quantity' => 2, 'discountBasisPoints' => 1250],
        ['itemId' => 8, 'quantity' => 1],
        ['itemId' => 12, 'quantity' => 1],
    ]);
});

it('rejects invalid discount basis points', function (mixed $discount) {
    expect(fn () => InvoiceXpressIssueTerms::lineSelections(8, 1, [
        ['itemId' => 8, 'quantity' => 1, 'discountBasisPoints' => $discount],
    ], [8], 10))->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('discountBasisPoints'));
})->with([[-1], [10001], [1.5], [true], [null], ['12.50']]);

it('rounds the line discount before calculating VAT in cents', function () {
    expect(InvoiceXpressIssueTerms::totals(5, 3, 2300, 1000))->toBe(['net' => 13, 'tax' => 3, 'total' => 16, 'discount' => 2])
        ->and(InvoiceXpressIssueTerms::totals(5, 1, 1000, 10000))->toBe(['net' => 0, 'tax' => 0, 'total' => 0, 'discount' => 5])
        ->and(InvoiceXpressIssueTerms::totals(5, 1, 0, 1))->toBe(['net' => 5, 'tax' => 0, 'total' => 5])
        ->and(fn () => InvoiceXpressIssueTerms::totals(5, 1, 0, 10001))->toThrow(ValidationException::class)
        ->and(fn () => InvoiceXpressIssueTerms::lineSelections(8, 1, [], [8], 10, discountBasisPoints: -1))->toThrow(ValidationException::class);
});

it('matches the exact provider discount percentage and discounted line amounts', function () {
    $expected = ['name' => 'Consultoria', 'description' => 'Serviço', 'unit' => 'service', 'quantity' => 3, 'discount' => '12.50'];
    $item = ['unitCents' => 8];
    $tax = ['id' => 9, 'name' => 'IVA23', 'basisPoints' => 2300];
    $totals = ['net' => 21, 'tax' => 5, 'total' => 26, 'discount' => 3];
    $line = [...$expected, 'unit_price' => '0.08', 'discount' => 12.5, 'discount_amount' => '0.03',
        'subtotal' => '0.21', 'tax_amount' => '0.05', 'total' => '0.26', 'tax' => ['id' => 9, 'name' => 'IVA23', 'value' => 23]];

    expect(InvoiceXpressIssueTerms::matchesLine($line, $expected, $item, $tax, $totals))->toBeTrue()
        ->and(InvoiceXpressIssueTerms::matchesLine([
            ...$line, 'discount_amount' => 0.0300, 'subtotal' => 0.21, 'tax_amount' => 0.0483, 'total' => 0.2583,
        ], $expected, $item, $tax, $totals))->toBeTrue()
        ->and(InvoiceXpressIssueTerms::matchesLine(Arr::except($line, ['discount_amount']), $expected, $item, $tax, $totals))->toBeTrue()
        ->and(InvoiceXpressIssueTerms::matchesLine([...$line, 'discount' => '12.501'], $expected, $item, $tax, $totals))->toBeFalse()
        ->and(InvoiceXpressIssueTerms::matchesLine([...$line, 'discount' => '12.51'], $expected, $item, $tax, $totals))->toBeFalse()
        ->and(InvoiceXpressIssueTerms::matchesLine([...$line, 'discount_amount' => '0.02'], $expected, $item, $tax, $totals))->toBeFalse()
        ->and(InvoiceXpressIssueTerms::matchesLine([...$line, 'subtotal' => '0.24'], $expected, $item, $tax, $totals))->toBeFalse()
        ->and(InvoiceXpressIssueTerms::matchesLine([...$line, 'tax_amount' => '0.06'], $expected, $item, $tax, $totals))->toBeFalse()
        ->and(InvoiceXpressIssueTerms::matchesLine([...$line, 'total' => '0.27'], $expected, $item, $tax, $totals))->toBeFalse();
});
