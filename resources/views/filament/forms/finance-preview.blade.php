@php
    $badgeClasses = fn (string $color): string => Illuminate\Support\Arr::toCssClasses(
        Filament\Support\get_component_color_classes(Filament\Support\View\Components\BadgeComponent::class, $color),
    );
@endphp

<span
    x-load
    x-load-src="{{ Illuminate\Support\Facades\Vite::asset('resources/js/finance-preview.js') }}"
    x-data="financePreview({
        read: $get,
        locale: @js(App\Enums\Language::resolve(app()->getLocale())->value),
        mode: @js($mode),
        messages: @js($messages ?? []),
        field: @js($field ?? null),
    })"
    @if ($mode === 'plan')
        x-bind:class="difference === 0n ? 'text-success-600 dark:text-success-400' : 'text-warning-600 dark:text-warning-400'"
    @elseif ($mode === 'expenseKind')
        class="fi-badge"
        x-bind:class="$get('kind') === 'direct' ? @js($badgeClasses('primary')) : @js($badgeClasses(App\Enums\ExpenseKind::Overhead->getColor()))"
    @elseif ($mode === 'choice')
        class="fi-badge {{ $badgeClasses('gray') }}"
        x-bind:class="{ 'hidden': text === '' }"
    @elseif ($mode === 'expensePaid')
        class="fi-badge"
        x-bind:class="$get('is_paid') ? @js($badgeClasses('success')) : @js($badgeClasses('warning'))"
    @endif
    x-text="text"
    aria-live="polite"
>{{ $initial }}</span>
