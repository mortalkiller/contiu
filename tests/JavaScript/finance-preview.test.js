import assert from 'node:assert/strict';
import { test } from 'node:test';
import { invoiceNetSubtotal, moneyCents, vatCents } from '../../resources/js/finance-preview.js';

test('rounds typed euros and VAT half up in integer cents', () => {
    assert.equal(moneyCents('151,98'), 15198n);
    assert.equal(moneyCents('1 965,505'), 196551n);
    assert.equal(moneyCents('-0.005'), -1n);
    assert.equal(moneyCents('1e-2'), 1n);
    assert.equal(moneyCents(''), 0n);
    assert.equal(vatCents(50n, '0.2300'), 12n);
    assert.equal(vatCents(10005n, '0.0600'), 600n);
});

test('reads deferred state as amounts and instalments change', async () => {
    const { default: makePreview } = await import('../../resources/js/finance-preview.js');
    const state = { net_amount: '0,50', vat_rate: '0.2300', receivables: { first: { amount: '0,60' } } };
    const preview = makePreview({
        read: (field) => state[field],
        mode: 'plan',
        locale: 'en-US',
        messages: { below: ':planned, :difference short of :total', exact: 'Complete' },
    });

    assert.equal(preview.text, '€0.60, €0.02 short of €0.62');
    state.receivables.second = { amount: '0.02' };
    assert.equal(preview.text, 'Complete');
});


test('uses cached catalogue cents and rounds the discount separately for each line', () => {
    const prices = { 8: 5, 12: 5000 };
    const lines = [
        { itemId: '8', quantity: '1', discount: '50' },
        { itemId: '12', quantity: '2', discount: '12,50' },
    ];

    assert.equal(invoiceNetSubtotal(prices, lines), 8752n);
    assert.equal(invoiceNetSubtotal(prices, [{ ...lines[0], itemId: '21' }]), null);
    assert.equal(invoiceNetSubtotal(prices, [{ ...lines[0], discount: '12.341' }]), null);
});
