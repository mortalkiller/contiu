import { moneyCents, moneyFormatter, scaledInteger } from '../../../finance-preview.js';

/*
 * What a fiscal document's lines add up to while it is being written, worked out in the browser
 * so that typing a quantity or a price never asks the server. The sums mirror
 * App\Support\FiscalDocumentTotals, which stays the one that counts when the document is saved:
 * integer cents, rounded half up, VAT charged per rate on the sum of that rate's lines.
 */

const share = (amount, basisPoints) => (amount * basisPoints + 5000n) / 10000n;

const basisPoints = (value) => (/^\d{1,5}$/.test(String(value ?? '').trim()) ? BigInt(String(value).trim()) : 0n);

/** One line as it is typed: decimal quantity, price in euros, discount as a percentage, VAT in basis points. */
export function lineAmounts(line) {
    const gross = (scaledInteger(line?.quantity, 3) * moneyCents(line?.unit_price) + 500n) / 1000n;
    const discount = share(gross, scaledInteger(line?.discount, 2));

    return { gross, discount, net: gross - discount, rate: basisPoints(line?.vat_basis_points) };
}

/** The document's amounts in cents, from the lines on screen and the withholding rate in basis points. */
export function documentTotals(lines, withholdingBasisPoints = 0) {
    const netByRate = new Map();
    let gross = 0n;
    let discount = 0n;

    for (const line of Object.values(lines ?? {})) {
        const amounts = lineAmounts(line);

        gross += amounts.gross;
        discount += amounts.discount;
        netByRate.set(amounts.rate, (netByRate.get(amounts.rate) ?? 0n) + amounts.net);
    }

    let net = 0n;
    let vat = 0n;

    for (const [rate, rateNet] of netByRate) {
        net += rateNet;
        vat += share(rateNet, rate);
    }

    const withholding = share(net, basisPoints(withholdingBasisPoints));

    return { gross, discount, net, vat, total: net + vat, withholding, payable: net + vat - withholding };
}

/** The article a line's description names, whatever its case, or null for a line written by hand. */
export function articleNamed(articles, description) {
    const name = String(description ?? '').trim().toLocaleLowerCase();

    return name === '' ? null : (articles ?? []).find((article) => String(article.name).toLocaleLowerCase() === name) ?? null;
}

/**
 * The articles the lines can be filled from. The repeater carries them once, URL-encoded so that
 * no article name can ever break out of the attribute it travels in.
 */
export function articlesNear(element) {
    try {
        return JSON.parse(decodeURIComponent(element?.closest('[data-articles]')?.dataset.articles ?? '')) ?? [];
    } catch {
        return [];
    }
}

/** Whether the lines charge no VAT at all, which is when the document must say why. */
export function chargesNoVat(lines) {
    return documentTotals(lines).vat === 0n;
}

function element(tag, className, text) {
    const node = document.createElement(tag);
    node.className = className;

    if (text !== undefined) {
        node.textContent = text;
    }

    return node;
}

/** A label and its amount, in the markup Filament gives an entry with an inline label. */
function entry(label, value, bold = false) {
    const row = element('div', `fi-in-entry fi-in-entry-has-inline-label${bold ? ' fap-key-value-total' : ''}`);
    const labelColumn = element('div', 'fi-in-entry-label-col');
    const labelContainer = element('div', 'fi-in-entry-label-ctn');
    const contentColumn = element('div', 'fi-in-entry-content-col');
    const contentContainer = element('div', 'fi-in-entry-content-ctn');
    const content = element('div', 'fi-in-entry-content');

    labelContainer.append(element('div', 'fi-in-entry-label', label));
    labelColumn.append(labelContainer);
    content.append(element('div', `fi-in-text-item fi-size-sm${bold ? ' fi-font-bold' : ''} fi-in-text fi-wrapped`, value));
    contentContainer.append(content);
    contentColumn.append(contentContainer);
    row.append(labelColumn, contentColumn);

    return row;
}

/**
 * Every line the kind of document can have is always drawn, with a dash while there is nothing
 * for it (no discount, no withholding), so the summary does not change shape as the user types.
 */
function documentRows({ labels, messages, classes, receivables, showsVat, showsWithholding }, read, format) {
    const totals = documentTotals(read('lines'), read('withholding_basis_points'));
    const none = '—';
    const rows = [];

    if (showsVat) {
        rows.push(
            entry(labels.gross, format(totals.gross)),
            entry(labels.discount, totals.discount > 0n ? format(-totals.discount) : none),
            entry(labels.net, format(totals.net)),
            entry(labels.vat, format(totals.vat)),
        );
    }

    rows.push(entry(labels.total, format(totals.total), true));

    if (showsWithholding) {
        rows.push(
            entry(labels.withholding, totals.withholding > 0n ? format(-totals.withholding) : none),
            entry(labels.payable, format(totals.payable)),
        );
    }

    const receivable = receivables?.[String(read('receivable_id') ?? '')];

    if (receivable !== undefined) {
        const matches = BigInt(receivable) === totals.total;
        const message = (matches ? messages.matches : messages.differs)
            .replace(':amount', format(matches ? totals.total : BigInt(receivable)));

        rows.push(element('div', `fi-in-text-item fi-size-sm fi-in-text fi-wrapped ${matches ? classes.matches : classes.differs}`, message));
    }

    return rows.map((row) => {
        const column = element('div', 'fi-grid-col');
        column.append(row);

        return column;
    });
}

function lineRows(read, format) {
    const amounts = lineAmounts({
        quantity: read('quantity'), unit_price: read('unit_price'), discount: read('discount'),
    });

    return [element('div', 'fi-in-text-item fi-size-sm fi-font-medium fi-in-text fi-wrapped fi-align-end', format(amounts.net))];
}

/**
 * Filament's renderer for the totals of the document ("document" mode) and for the total of one
 * line ("line" mode). Reading the form's state inside an Alpine effect is what redraws it as the
 * user types.
 */
export default function mountFiscalDocumentTotals({ host, props, utilities }) {
    let configuration = props.configuration;
    const read = (path) => utilities.$get(path);

    host.setAttribute('aria-live', 'polite');

    const render = () => {
        const format = moneyFormatter(configuration.locale);

        if (configuration.mode === 'line') {
            host.className = 'fi-in-entry-content fi-align-end';
            host.replaceChildren(...lineRows(read, format));

            return;
        }

        host.className = 'fi-grid fi-sc fi-sc-has-gap';
        host.replaceChildren(...documentRows(configuration, read, format));
    };

    const effect = window.Alpine.effect(render);

    return {
        update(next) {
            configuration = next.configuration;
            render();
        },
        destroy() {
            window.Alpine.release(effect);
            host.replaceChildren();
        },
    };
}
