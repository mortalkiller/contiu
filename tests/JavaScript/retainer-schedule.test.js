import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import makePreview, { frequencyMonths, perMonthCents, perYearCents, retainerFirstDue } from '../../resources/js/finance-preview.js';

// The same cases the server is held to in tests/Feature/RetainerSchedulePreviewTest.php.
const cases = JSON.parse(readFileSync(new URL('./retainer-schedule.cases.json', import.meta.url), 'utf8'));

for (const { name, total, frequency, starts_on: startsOn, due_day: dueDay, expected } of cases) {
    test(`works out ${name} as the server does`, () => {
        const months = frequencyMonths(frequency);

        assert.deepEqual({
            per_month: Number(perMonthCents(BigInt(total), months)),
            per_year: Number(perYearCents(BigInt(total), months)),
            first_due: retainerFirstDue(startsOn, dueDay, months),
        }, expected);
    });
}

test('says nothing about the first charge until the start and the day are known', () => {
    assert.equal(retainerFirstDue('', 1, 1), null);
    assert.equal(retainerFirstDue('2026-01-01', '', 1), null);
    assert.equal(retainerFirstDue('2026-01-01', 1, null), null);
});

test('counts the instalments and names the last due date as they are typed', () => {
    const state = { receivables: {} };
    const preview = makePreview({
        read: (field) => state[field],
        mode: 'instalments',
        locale: 'pt-PT',
        messages: { some: ':count · até :date' },
    });

    assert.equal(preview.text, '—');
    state.receivables = { a: { due_on: '2026-12-11' }, b: { due_on: '2026-11-11' } };
    assert.equal(preview.text, '2 · até 11/12/2026');
});

test('shows the label of the option chosen in a field', () => {
    const state = { status: 'approved' };
    const preview = makePreview({
        read: (field) => state[field],
        mode: 'choice',
        field: 'status',
        locale: 'pt-PT',
        messages: { approved: 'Aprovado', proposal: 'Proposta' },
    });

    assert.equal(preview.text, 'Aprovado');
    state.status = 'proposal';
    assert.equal(preview.text, 'Proposta');
});
