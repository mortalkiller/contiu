@php
    use ToneGabes\Filament\Icons\Enums\Phosphor;

    $readOnlyTooltip = $readOnly ? __('The account is read-only') : null;
@endphp

<x-filament-widgets::widget>
    <div class="fap-edge-to-edge grid overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 md:grid-cols-[15rem_1fr] dark:bg-gray-900 dark:ring-white/10">
        {{-- Summary: the month's total and the next deadline. --}}
        <div class="flex flex-col bg-(--contiu-brand-surface) p-5 text-white">
            <p class="text-xs">{{ __('Due to the authorities in :month', ['month' => $month]) }}</p>
            <p class="text-3xl font-bold tracking-tight tabular-nums">{{ $pendingTotal }}</p>
            <p class="text-xs">
                @if ($pendingCount === 0)
                    {{ __('all up to date ✓') }}
                @else
                    {{ trans_choice('{1} to pay|[2,*] to pay · :count tasks', $pendingCount) }}
                @endif
            </p>

            @if ($next)
                <p class="mt-4 text-sm">{{ __('Next:') }} <span class="font-semibold">{{ $next }}</span></p>
            @endif

            <div class="mt-auto flex flex-col items-start gap-2 pt-4 text-xs">
                @if ($socialSecurity)
                    <p>{{ $socialSecurity }}</p>
                @endif

                <a href="{{ $calendarUrl }}" class="inline-flex items-center gap-1 font-semibold underline underline-offset-2">
                    {{ __('View calendar') }}
                    <x-filament::icon :icon="Phosphor::CaretRight" class="size-3" />
                </a>
            </div>
        </div>

        {{-- min-w-0: a grid cell is otherwise as wide as its longest row, and pushes the card off a phone screen. --}}
        <div class="min-w-0 px-5 pb-4 pt-1 text-sm">
            <p class="pt-2 text-xs text-gray-500 dark:text-gray-400">
                {{ trans_choice('{0} Nothing to do.|{1} You have 1 thing to do.|[2,*] You have :count things to do.', $taskCount) }}
            </p>
            @if ($nothingToPay && $tasks === [])
                <p class="flex items-center gap-2 border-b border-gray-950/5 py-3 font-medium text-success-700 dark:border-white/10 dark:text-success-400">
                    <x-filament::icon :icon="Phosphor::CheckCircle" class="size-4" />
                    {{ __('Nothing to pay this month') }}
                </p>
            @endif

            @foreach ($tasks as $row)
                @include('filament.widgets.partials.tax-obligation-row', ['row' => $row, 'readOnlyTooltip' => $readOnlyTooltip])
            @endforeach

            @if ($hiddenCount > 0)
                <div class="flex justify-center border-b border-gray-950/5 py-1 dark:border-white/10">
                    <x-filament::link
                        tag="button"
                        size="sm"
                        color="gray"
                        :icon="$showAll ? Phosphor::CaretUp : Phosphor::CaretDown"
                        icon-position="after"
                        wire:click="toggleAll"
                    >
                        {{ $showAll ? __('Show less') : __('Show :count more', ['count' => $hiddenCount]) }}
                    </x-filament::link>
                </div>
            @endif

            @if ($limits !== [])
                <div class="flex flex-col gap-4 pt-4 sm:flex-row sm:gap-7">
                    @foreach ($limits as $limit)
                        <div class="flex-1" x-tooltip="{ content: @js($limit['tooltip']), theme: $store.theme }">
                            <div class="mb-1.5 flex justify-between text-xs text-gray-500 dark:text-gray-400">
                                <span>{{ $limit['label'] }}</span>
                                <span @class([
                                    'font-semibold tabular-nums',
                                    'text-gray-950 dark:text-white' => $limit['color'] === 'primary',
                                    'text-warning-600 dark:text-warning-400' => $limit['color'] === 'warning',
                                    'text-danger-600 dark:text-danger-400' => $limit['color'] === 'danger',
                                ])>{{ $limit['percent'] }}</span>
                            </div>
                            <div class="h-1 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                                <div @class([
                                    'h-full rounded-full',
                                    'bg-primary-600 dark:bg-primary-400' => $limit['color'] === 'primary',
                                    'bg-warning-500 dark:bg-warning-400' => $limit['color'] === 'warning',
                                    'bg-danger-600 dark:bg-danger-500' => $limit['color'] === 'danger',
                                ]) style="width: {{ $limit['width'] }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($upcoming !== '' || $done !== [])
                <div class="flex flex-wrap items-center justify-between gap-2 pt-3 text-xs text-gray-400 dark:text-gray-500">
                    <span>{{ $upcoming !== '' ? __('Coming up: :upcoming', ['upcoming' => $upcoming]) : '' }}</span>

                    @if ($done !== [])
                        <x-filament::link tag="button" size="xs" color="gray" wire:click="toggleDone">
                            {{ $showDone ? __('Hide completed') : __('Show completed (:count)', ['count' => count($done)]) }}
                        </x-filament::link>
                    @endif
                </div>
            @endif

            @if ($showDone && $done !== [])
                <div class="mt-1">
                    @foreach ($done as $row)
                        @include('filament.widgets.partials.tax-obligation-row', ['row' => $row, 'readOnlyTooltip' => $readOnlyTooltip, 'isDone' => true])
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
