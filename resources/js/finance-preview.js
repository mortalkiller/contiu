function scaledInteger(value, scale) {
    const input = String(value ?? '').replaceAll(' ', '').replace(',', '.');
    const parts = /^([+-]?)(\d*)(?:\.(\d*))?(?:e([+-]?\d+))?$/i.exec(input);

    if (!parts || !(parts[2] || parts[3])) {
        return 0n;
    }

    const [, sign, whole, fraction = '', exponent = '0'] = parts;
    const digits = whole + fraction;
    const shift = scale + Number(exponent) - fraction.length;

    if (!Number.isSafeInteger(shift) || shift > 20 || shift < -digits.length) {
        return 0n;
    }

    const unsigned = BigInt(digits);
    const factor = 10n ** BigInt(Math.abs(shift));
    const scaled = shift >= 0 ? unsigned * factor : (unsigned + factor / 2n) / factor;

    return sign === '-' ? -scaled : scaled;
}

export function moneyCents(value) {
    return scaledInteger(value, 2);
}

export function vatCents(net, rate) {
    const product = net * scaledInteger(rate, 4);
    const sign = product < 0n ? -1n : 1n;

    return ((product * sign + 5000n) / 10000n) * sign;
}

export function invoiceNetSubtotal(prices, lines) {
    let subtotal = 0n;

    for (const { itemId, quantity, discount } of lines) {
        const unitCents = prices[itemId];
        const percent = String(discount ?? '').trim();

        if (!Object.hasOwn(prices, itemId) || !/^[1-9]\d{0,3}$/.test(String(quantity)) || Number(quantity) > 1000
            || (percent !== '' && !/^\d{1,12}(?:[.,]\d{1,2}0*)?$/.test(percent))) {
            return null;
        }

        const basisPoints = scaledInteger(percent, 2);

        if (basisPoints > 10000n) {
            return null;
        }

        const gross = BigInt(unitCents) * BigInt(quantity);
        subtotal += gross - (gross * basisPoints + 5000n) / 10000n;
    }

    return subtotal;
}

export default function financePreview({ read, locale, mode, messages, prices }) {
    const formatter = new Intl.NumberFormat(locale, { style: 'currency', currency: 'EUR' });

    function format(cents) {
        const euros = cents / 100n;
        const fraction = (cents < 0n ? -cents : cents) % 100n;

        return formatter.formatToParts(euros === 0n && cents < 0n ? -0 : euros)
            .map(({ type, value }) => type === 'fraction' ? String(fraction).padStart(2, '0') : value)
            .join('');
    }

    return {
        get net() {
            return moneyCents(read('net_amount'));
        },

        get vat() {
            return vatCents(this.net, read('vat_rate'));
        },

        get total() {
            return this.net + this.vat;
        },

        get planned() {
            return Object.values(read('receivables') ?? {})
                .reduce((sum, row) => sum + moneyCents(row?.amount), 0n);
        },

        get difference() {
            return this.total - this.planned;
        },

        get text() {
            if (mode === 'invoiceSubtotal') {
                const subtotal = invoiceNetSubtotal(prices, [{
                    itemId: read('itemId'), quantity: read('quantity'), discount: read('discount'),
                }, ...read('additionalLines')]);

                return subtotal === null ? '' : messages.subtotal.replace(':amount', format(subtotal));
            }

            if (mode === 'vatLabel') {
                return messages.rate.replace(':rate', scaledInteger(read('vat_rate'), 2).toString());
            }

            if (mode === 'expenseKind') {
                return messages[read('kind')] ?? '';
            }

            if (mode === 'expensePaid') {
                if (!read('is_paid')) {
                    return messages.unpaid;
                }

                const date = new Date(String(read('paid_on') ?? '').slice(0, 10) + 'T00:00:00Z');

                if (Number.isNaN(date.getTime())) {
                    return messages.paid;
                }

                const displayDate = new Intl.DateTimeFormat(locale, {
                    day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'UTC',
                }).format(date);

                return messages.dated.replace(':date', displayDate);
            }

            if (mode !== 'plan') {
                return format(this[mode]);
            }

            const difference = this.difference;

            if (difference === 0n) {
                return messages.exact;
            }

            return (difference > 0n ? messages.below : messages.above)
                .replace(':planned', format(this.planned))
                .replace(':difference', format(difference > 0n ? difference : -difference))
                .replace(':total', format(this.total));
        },
    };
}
