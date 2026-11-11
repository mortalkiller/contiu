@php
    use App\Enums\CalendarEntryType;
    use App\Support\CurrentAccount;
    use Filament\Support\View\Components\ButtonComponent;
    use Filament\Support\View\ComponentAttributeBag;
@endphp

<div
    wire:ignore
    data-calendar-types
    class="fi-fo-toggle-buttons fi-inline"
    role="group"
    aria-label="{{ __('filament-tables::table.filters.heading') }}"
    x-data="{
        calendarApi: null,
        storageKey: @js('contiu:calendar-types:'.auth()->id().':'.CurrentAccount::id()),
        init() {
            try {
                const stored = localStorage.getItem(this.storageKey)
                if (stored === null) return

                const selected = new Set(stored.split(','))
                this.$el.querySelectorAll('input').forEach(input => { input.checked = selected.has(input.value) })
            } catch {
                // The browser may disable storage.
            }
        },
        saveTypes() {
            const selected = Array.from(this.$el.querySelectorAll('input:checked'), input => input.value)

            try {
                localStorage.setItem(this.storageKey, selected.join(','))
            } catch {
                // The browser may disable storage.
            }
        },
        updateType(input) {
            const calendar = Alpine.raw(this.calendarApi)
            const event = calendar?.getEvents().find(event => event.groupId === input.value)

            // FullCalendar applies display to every event that shares this groupId.
            event?.setProp('display', input.checked ? 'auto' : 'none')
        },
        resetTypes() {
            const unchecked = this.$el.querySelectorAll('input:not(:checked)')
            unchecked.forEach(input => { input.checked = true })
            Alpine.raw(this.calendarApi)?.batchRendering(() => unchecked.forEach(input => this.updateType(input)))
            this.saveTypes()
        },
    }"
    x-on:contiu-calendar-ready="calendarApi = $event.detail"
    x-on:contiu-calendar-reset="resetTypes()"
    x-on:change="updateType($event.target); saveTypes()"
>
    @foreach (CalendarEntryType::cases() as $type)
        @php
            $id = $this->getId().'-'.$type->value;
            $color = match ($type) {
                CalendarEntryType::Obligation => 'warning',
                CalendarEntryType::Payment => 'primary',
                CalendarEntryType::Receivable => 'info',
                default => 'gray',
            };
            $label = (new ComponentAttributeBag)->class(['fi-btn', 'fi-size-md'])->color(ButtonComponent::class, $color);
        @endphp

        <div class="fi-fo-toggle-buttons-btn-ctn">
            <input class="fi-fo-toggle-buttons-input" type="checkbox" id="{{ $id }}" value="{{ $type->value }}" checked>
            <label for="{{ $id }}" {{ $label }}>{{ $type->getLabel() }}</label>
        </div>
    @endforeach
</div>
