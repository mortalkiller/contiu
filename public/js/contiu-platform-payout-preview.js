const maxInt = 9223372036854775807n
const minInt = -maxInt - 1n

function decimalInput(value) {
    return String(value ?? '').replace(/^[\x00\t\n\r\v ]+|[\x00\t\n\r\v ]+$/g, '')
}

function minorUnits(value, scale) {
    const match = /^([0-9]{1,19})(?:[.,]([0-9]{1,4}))?$/.exec(decimalInput(value))
    if (!match) return null

    const [, whole, fraction = ''] = match
    if (/[1-9]/.test(fraction.slice(scale))) return null

    const amount = BigInt(whole + fraction.slice(0, scale).padEnd(scale, '0'))
    return amount <= maxInt ? amount : null
}

function netEur(payout, scales) {
    const scale = scales[payout?.currency]
    if (!Number.isInteger(scale)) return null

    const gross = minorUnits(payout.original_gross, scale)
    const fee = minorUnits(payout.original_commission, scale)
    if (gross === null || fee === null || gross <= fee) return null
    if (payout.currency === 'EUR') return gross - fee

    const match = /^([0-9]{1,12})(?:[.,]([0-9]{1,12}))?$/.exec(decimalInput(payout.fx_rate))
    if (!match) return null

    const [, whole, fraction = ''] = match
    const rate = BigInt(whole + fraction)
    if (rate === 0n) return null

    const divisor = 10n ** BigInt(scale + fraction.length)
    // Match the server's half-up rounding of gross and commission individually.
    const convert = (amount) => (amount * rate * 100n + divisor / 2n) / divisor
    const grossEur = convert(gross)
    const feeEur = convert(fee)
    if (grossEur > maxInt || grossEur <= feeEur || (fee > 0n && feeEur === 0n)) return null

    return grossEur - feeEur
}

export default function contiuPlatformPayoutPreview({ amount, locale, scales, difference, overflow }) {
    const currency = new Intl.NumberFormat(locale, { style: 'currency', currency: 'EUR' })

    return {
        get preview() {
            let remaining = BigInt(amount)

            for (const payout of Object.values(this.$get('payouts') ?? {})) {
                const net = netEur(payout, scales)
                if (net === null) return ''
                remaining -= net
            }

            if (remaining < minInt || remaining > maxInt) return overflow

            return difference.replace(':amount', currency.format(Number(remaining) / 100))
        },
    }
}
