@php
    use ToneGabes\Filament\Icons\Enums\Phosphor;
@endphp

<x-filament-widgets::widget>
    <x-filament::section :heading="__('Upcoming deadlines')" :icon="Phosphor::CalendarCheck" compact>
        @if ($rows === [])
            <p class="flex items-center gap-2 text-sm font-medium text-success-700 dark:text-success-400">
                <x-filament::icon :icon="Phosphor::CheckCircle" class="size-4" />
                {{ __('No outstanding deadlines') }}
            </p>
        @else
            <ul class="-my-2 divide-y divide-gray-950/5 text-sm dark:divide-white/10">
                @foreach ($rows as $row)
                    <li class="flex items-start gap-3 py-2.5" wire:key="deadline-{{ $row['key'] }}">
                        <div class="min-w-0 flex-1" x-tooltip="{ content: @js($row['tooltip']), theme: $store.theme }">
                            @if ($row['url'])
                                <a href="{{ $row['url'] }}" class="font-semibold text-gray-950 hover:underline dark:text-white">{{ $row['title'] }}</a>
                            @else
                                <span class="font-semibold text-gray-950 dark:text-white">{{ $row['title'] }}</span>
                            @endif

                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                @if ($row['amount'])
                                    <span class="font-medium tabular-nums text-gray-700 dark:text-gray-300">{{ $row['amount'] }}</span> ·
                                @endif
                                {{ $row['when'] }}
                            </p>
                        </div>

                        <x-filament::badge :color="$row['tone']" size="sm" class="shrink-0">
                            {{ $row['badge'] }}
                        </x-filament::badge>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
