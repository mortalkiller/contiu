import assert from 'node:assert/strict';
import test from 'node:test';

import bankMatchPreview from '../../public/js/contiu-bank-match-preview.js';
import platformPayoutPreview from '../../public/js/contiu-platform-payout-preview.js';

test('shows a registered expense difference and clears an unavailable explanation', () => {
    const state = { targets: ['paid:1'], explanation: 'bank_fee' };
    const preview = bankMatchPreview({
        amount: '-15100',
        targets: { 'paid:1': '15198', 'paid:2': '15000' },
        locale: 'en-US',
        message: 'Difference: :difference',
        explanations: { more: 'bank_fee', less: null },
    });
    preview.$get = (key) => state[key];
    preview.$set = (key, value) => { state[key] = value; };

    assert.equal(preview.warning, 'Difference: €0.98');
    assert.equal(preview.explanation, null);
    preview.clearUnavailableExplanation();
    assert.equal(state.explanation, null);

    state.targets = ['paid:2'];
    assert.equal(preview.warning, 'Difference: €1.00');
    assert.equal(preview.explanation, 'bank_fee');
});

test('uses registered amounts first and caps new payments at the outstanding balance', () => {
    let targets = ['partial:1', 'payment:2', 'paid:3', 'receivable:4'];
    const preview = bankMatchPreview({
        amount: '10000',
        targets: { 'partial:1': '4000', 'payment:2': '3000', 'paid:3': '1000', 'receivable:4': '8000' },
        locale: 'en-US',
        message: ':difference',
        explanations: { more: 'other_income', less: 'bank_fee' },
    });
    preview.$get = () => targets;

    assert.equal(preview.difference, 0n);
    targets = targets.slice(0, 3);
    assert.equal(preview.difference, 4000n);
    assert.equal(preview.explanation, 'other_income');
});

test('converts gross and fee separately using half-up minor-unit arithmetic', () => {
    const rows = {
        first: { currency: 'USD', original_gross: '0,04', original_commission: '0,01', fx_rate: '0,5' },
        second: { currency: 'JPY', original_gross: '3', original_commission: '0', fx_rate: '0.005' },
    };
    const preview = platformPayoutPreview({
        amount: '3',
        locale: 'en-US',
        scales: { USD: 2, JPY: 0 },
        difference: 'Difference: :amount',
        overflow: 'Overflow',
    });
    preview.$get = () => rows;

    assert.equal(preview.preview, 'Difference: €0.00');
    rows.second.original_gross = '3.01';
    assert.equal(preview.preview, '');
    rows.second.original_gross = '3';
    rows.first.fx_rate = '0.1';
    assert.equal(preview.preview, '');
});
