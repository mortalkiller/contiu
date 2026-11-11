function plannedDifference(statementAmount, keys, amounts) {
    if (!keys?.length) return null

    const direction = statementAmount > 0n ? 1n : -1n
    const toCreate = []
    let existing = 0n

    for (const key of new Set(keys)) {
        if (!Object.hasOwn(amounts, key)) return null

        const [type] = key.split(':')
        const amount = amounts[key] === null ? null : BigInt(amounts[key])

        if (type === 'payment') {
            existing += amount
        } else if (type === 'paid' || type === 'expense') {
            existing -= amount
        } else {
            toCreate.push({ amount, isReceivable: type === 'receivable' || type === 'partial' })
        }
    }

    let total = existing
    let remaining = (statementAmount - existing) * direction

    for (const { amount, isReceivable } of toCreate) {
        if (remaining <= 0n) return null

        const applied = isReceivable && amount < remaining ? amount : remaining
        total += isReceivable ? applied : -applied
        remaining -= applied
    }

    return statementAmount - total
}

export default function contiuBankMatchPreview({ amount, targets, locale, message, explanations }) {
    const statementAmount = BigInt(amount)
    const currency = new Intl.NumberFormat(locale, { style: 'currency', currency: 'EUR' })

    return {
        get difference() {
            return plannedDifference(statementAmount, this.$get('targets'), targets)
        },
        get warning() {
            const difference = this.difference
            if (difference === null || difference === 0n) return ''

            const absolute = difference < 0n ? -difference : difference
            return message.replace(':difference', currency.format(Number(absolute) / 100))
        },
        get explanation() {
            const difference = this.difference
            if (difference === null || difference === 0n) return null

            return (difference > 0n) === (statementAmount > 0n) ? explanations.more : explanations.less
        },
        clearUnavailableExplanation() {
            const explanation = this.$get('explanation')
            if (explanation && explanation !== this.explanation) this.$set('explanation', null)
        },
    }
}
