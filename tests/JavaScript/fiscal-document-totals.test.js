import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { articleNamed, articlesNear, chargesNoVat, documentTotals, lineAmounts } from '../../resources/js/filament/schemas/components/fiscal-document-totals.js';

// The same cases the server is held to in tests/Feature/Invoicing/FiscalDocumentTotalsPreviewTest.php.
const cases = JSON.parse(readFileSync(new URL('./fiscal-document-totals.cases.json', import.meta.url), 'utf8'));

for (const { name, lines, withholding, expected } of cases) {
    test(`adds up ${name} as the server does`, () => {
        const totals = documentTotals(Object.fromEntries(lines.map((line, index) => [`row-${index}`, line])), withholding);

        assert.deepEqual(
            Object.fromEntries(Object.keys(expected).map((key) => [key, Number(totals[key])])),
            expected,
        );
    });
}

test('gives a line its amount after the discount, before VAT', () => {
    assert.equal(lineAmounts({ quantity: '2', unit_price: '45,50', discount: '10', vat_basis_points: '2300' }).net, 8190n);
    assert.equal(lineAmounts({ quantity: '', unit_price: '45' }).net, 0n);
});

test('says whether a document has to explain why it charges no VAT', () => {
    assert.equal(chargesNoVat({ a: { quantity: '1', unit_price: '10', vat_basis_points: '0' } }), true);
    assert.equal(chargesNoVat({ a: { quantity: '1', unit_price: '10', vat_basis_points: '2300' } }), false);
    assert.equal(chargesNoVat({ a: { quantity: '1', unit_price: '', vat_basis_points: '2300' } }), true);
});

test('finds the article a description names, whatever its case', () => {
    const articles = [{ id: 7, name: 'Hora de desenvolvimento', unit: 'h', price: '45.00', vat: null }];

    assert.equal(articleNamed(articles, '  hora de DESENVOLVIMENTO ')?.id, 7);
    assert.equal(articleNamed(articles, 'Hora'), null);
    assert.equal(articleNamed(articles, ''), null);
    assert.equal(articleNamed(undefined, 'Hora de desenvolvimento'), null);
});

test('reads the articles the repeater carries, and nothing from a broken attribute', () => {
    const carrying = (articles) => ({ closest: () => ({ dataset: { articles } }) });
    const articles = [{ id: 7, name: 'Caixa "grande" <b>', unit: 'cx', price: '3.50', vat: '2300' }];

    assert.deepEqual(articlesNear(carrying(encodeURIComponent(JSON.stringify(articles)))), articles);
    assert.deepEqual(articlesNear(carrying('[{\\"id')), []);
    assert.deepEqual(articlesNear({ closest: () => null }), []);
});
