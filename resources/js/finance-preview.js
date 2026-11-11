/** A typed decimal as an exact integer at the given scale (2 for cents), rounded half up. */
export function scaledInteger(value, scale) {
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

/** Writes integer cents as euros in the user's locale, without going through a float. */
export function moneyFormatter(locale) {
    const formatter = new Intl.NumberFormat(locale, { style: 'currency', currency: 'EUR' });

    return (cents) => {
        const euros = cents / 100n;
        const fraction = (cents < 0n ? -cents : cents) % 100n;

        return formatter.formatToParts(euros === 0n && cents < 0n ? -0 : euros)
            .map(({ type, value }) => type === 'fraction' ? String(fraction).padStart(2, '0') : value)
            .join('');
    };
}

const FREQUENCY_MONTHS = { monthly: 1, quarterly: 3, yearly: 12 };

/** Months in one period of a retainer's frequency, as Frequency::months(); null when unknown. */
export function frequencyMonths(frequency) {
    return FREQUENCY_MONTHS[frequency] ?? null;
}

/** A period's amount per year, as Retainer::yearlyValue (exact for 1, 3 and 12 months). */
export function perYearCents(total, months) {
    return total * 12n / BigInt(months);
}

/** A period's amount per month, rounded half up like Money::round. */
export function perMonthCents(total, months) {
    const divisor = BigInt(months);
    const sign = total < 0n ? -1n : 1n;

    return ((total * sign * 2n + divisor) / (2n * divisor)) * sign;
}

/**
 * The first due date of a new retainer, as GenerateRetainerPeriods::firstDueDate: the due day of
 * the start month (or its last day), or of the next period when that falls before the start.
 * Dates are 'YYYY-MM-DD'; null while the start or the day is missing.
 */
export function retainerFirstDue(startsOn, dueDay, months) {
    const start = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(startsOn ?? ''));
    const day = Number.parseInt(String(dueDay ?? ''), 10);

    if (!start || !Number.isInteger(day) || day < 1 || !months) {
        return null;
    }

    const [year, month, startDay] = [Number(start[1]), Number(start[2]), Number(start[3])];
    const onDueDay = (y, m) => {
        const last = new Date(Date.UTC(y, m, 0)).getUTCDate();
        const date = new Date(Date.UTC(y, m - 1, Math.min(day, last)));

        return date.toISOString().slice(0, 10);
    };

    const first = onDueDay(year, month);

    if (Math.min(day, new Date(Date.UTC(year, month, 0)).getUTCDate()) >= startDay) {
        return first;
    }

    return onDueDay(year + Math.floor((month - 1 + months) / 12), ((month - 1 + months) % 12) + 1);
}

export default function financePreview({ read, locale, mode, messages, prices, field }) {
    const format = moneyFormatter(locale);
    const formatDate = (value) => new Intl.DateTimeFormat(locale, {
        day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'UTC',
    }).format(new Date(value + 'T00:00:00Z'));

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

            if (mode === 'choice') {
                return messages[read(field)] ?? '';
            }

            if (mode === 'perMonth' || mode === 'perYear') {
                const months = frequencyMonths(read('frequency'));

                return months === null ? '—' : format((mode === 'perMonth' ? perMonthCents : perYearCents)(this.total, months));
            }

            if (mode === 'firstDue') {
                const due = retainerFirstDue(read('starts_on'), read('due_day'), frequencyMonths(read('frequency')));

                return due === null ? '—' : formatDate(due);
            }

            if (mode === 'instalments') {
                const rows = Object.values(read('receivables') ?? {});
                const last = rows.map((row) => String(row?.due_on ?? '').slice(0, 10))
                    .filter((due) => /^\d{4}-\d{2}-\d{2}$/.test(due))
                    .sort()
                    .at(-1);

                if (rows.length === 0) {
                    return '—';
                }

                return last === undefined
                    ? String(rows.length)
                    : messages.some.replace(':count', String(rows.length)).replace(':date', formatDate(last));
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
