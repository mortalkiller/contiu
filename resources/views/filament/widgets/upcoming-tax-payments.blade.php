{{-- "Próximos pagamentos" on the tax estimate (App\Filament\Widgets\UpcomingTaxPayments). --}}
@php
    $icon = [
        'danger' => \ToneGabes\Filament\Icons\Enums\Phosphor::Warning,
        'warning' => \ToneGabes\Filament\Icons\Enums\Phosphor::Clock,
        'gray' => \ToneGabes\Filament\Icons\Enums\Phosphor::CalendarBlank,
    ];
    $markClasses = [
        'danger' => 'bg-danger-50 text-danger-600 dark:bg-danger-400/10 dark:text-danger-400',
        'warning' => 'bg-warning-50 text-warning-600 dark:bg-warning-400/10 dark:text-warning-400',
        'gray' => 'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400',
    ];
@endphp

<x-filament-widgets::widget>
    <x-filament::section :heading="__('Upcoming payments')">
        @if ($rows === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing to pay or file for now.') }}</p>
        @else
            <ul class="-mx-6 -my-2 divide-y divide-gray-200 text-sm dark:divide-white/10">
                @foreach ($rows as $row)
                    <li wire:key="upcoming-{{ $row['key'] }}" class="flex items-center gap-3 px-6 py-3">
                        <span class="grid size-8 shrink-0 place-items-center rounded-lg {{ $markClasses[$row['tone']] }}">
                            <x-filament::icon :icon="$icon[$row['tone']]" class="size-4" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium text-gray-950 dark:text-white">{{ $row['title'] }}</span>
                            <span @class(['block', 'text-danger-700 dark:text-danger-400' => $row['tone'] === 'danger', 'text-gray-500 dark:text-gray-400' => $row['tone'] !== 'danger'])>{{ $row['when'] }}</span>
                            @if ($row['canMark'])
                                <x-filament::button size="xs" color="gray" class="mt-2" wire:click="markFiled('{{ $row['key'] }}')">{{ __('Mark as filed') }}</x-filament::button>
                            @endif
                        </span>
                        @if ($row['amount'])
                            <span class="whitespace-nowrap font-semibold tabular-nums text-gray-950 dark:text-white">{{ $row['amount'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
            @if ($hidden > 0)
                <a href="{{ $calendarUrl }}" class="mt-3 block text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">{{ trans_choice('{1} 1 more on the calendar|[2,*] :count more on the calendar', $hidden) }}</a>
            @endif
        @endif

        @if ($total)
            <dl class="-mx-6 -mb-2 mt-2 flex justify-between gap-3 border-t border-gray-200 px-6 pt-3 text-sm dark:border-white/10">
                <dt class="text-gray-600 dark:text-gray-400">{{ __('Still to pay') }}</dt>
                <dd class="font-semibold tabular-nums">{{ $total }}</dd>
            </dl>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
