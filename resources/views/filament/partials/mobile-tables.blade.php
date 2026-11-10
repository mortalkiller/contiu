{{--
    On a phone every Filament table is shown as a list of cards, one per record, instead of a wide
    table that scrolls sideways (theme.css, "Tables as lists on a phone"). CSS cannot read a
    column's heading from inside a cell, so this marks each cell with:
      data-label    the heading of its column, shown before the value;
      data-primary  on the column used as the card's title (the first plain-text column);
      data-wide     on cells with long text, which take a line of their own;
      data-empty    on cells with nothing to show, which are left out of the card.
    Livewire re-renders tables on every sort, filter or page change, so the marks are put back
    after each update.
--}}
<script>
    (() => {
        const text = (element) => element.textContent.replace(/\s+/g, ' ').trim()

        // A title is a name or a description: not a date, an amount, a badge or an icon.
        const canBeTitle = (cell) =>
            ! cell.matches('.fi-ta-selection-cell') &&
            ! cell.querySelector('.fi-ta-actions, .fi-badge, .fi-ta-icon, .fi-numeric, input, select') &&
            text(cell) !== '' &&
            ! /^\d{1,2}\/\d{1,2}\/\d{2,4}/.test(text(cell))

        const markTable = (table) => {
            const headings = [...table.querySelectorAll(':scope > thead > tr:last-child > th')].map(text)
            const rows = [...table.querySelectorAll(':scope > tbody > tr')]
            const records = rows.filter((row) => ! row.matches('.fi-ta-summary-header-row, .fi-ta-summary-row'))

            const firstRecord = records.find((row) => [...row.children].every((cell) => cell.colSpan === 1))
            const titleColumn = firstRecord ? [...firstRecord.children].findIndex(canBeTitle) : -1

            rows.forEach((row) => {
                let column = 0

                ;[...row.children].forEach((cell) => {
                    const isRecordCell = cell.colSpan === 1 && records.includes(row)

                    cell.dataset.label = cell.colSpan === 1 ? (headings[column] ?? '') : ''
                    cell.toggleAttribute('data-primary', isRecordCell && column === titleColumn)
                    cell.toggleAttribute('data-wide', isRecordCell && text(cell).length > 28)
                    cell.toggleAttribute(
                        'data-empty',
                        cell.colSpan === 1 && text(cell) === '' && ! cell.querySelector('svg, img, input, button, a[href]'),
                    )

                    column += cell.colSpan
                })
            })
        }

        const markTables = () => document.querySelectorAll('table.fi-ta-table').forEach(markTable)

        let queued = false
        const queue = () => {
            if (queued) {
                return
            }

            queued = true
            requestAnimationFrame(() => {
                queued = false
                markTables()
            })
        }

        document.addEventListener('DOMContentLoaded', queue)
        document.addEventListener('livewire:navigated', queue)
        document.addEventListener('livewire:init', () => Livewire.hook('morphed', queue))
        new MutationObserver(queue).observe(document.body, { childList: true, subtree: true })
    })()
</script>
