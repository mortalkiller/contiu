@php
    $formatMinimum = fn (int $cents): string => $this->formatMinimum($cents);
@endphp

<x-filament-panels::page>
    <form wire:submit="calculate" class="space-y-4">
        {{ $this->form }}

        <x-filament::button type="submit">
            {{ __('Calculate') }}
        </x-filament::button>
    </form>

    <div aria-live="polite">
        @if ($result !== null)
            <div wire:dirty.remove>
                <x-filament::section :heading="__('Minimum revenue')">
                    <dl class="grid gap-4 sm:grid-cols-3">
                        @foreach (['annual' => __('Per year'), 'monthly' => __('Per billable month'), 'hourly' => __('Per billable hour')] as $period => $label)
                            <div>
                                <dt class="text-sm text-gray-600 dark:text-gray-400">{{ $label }}</dt>
                                <dd class="mt-1 text-xl font-semibold tabular-nums">{{ $formatMinimum($result[$period]) }}</dd>
                                @if ($inputs !== null)
                                    <dd class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                                        {{ match ($period) {
                                            'annual' => __('Required revenue'),
                                            'monthly' => trans_choice('{1} Over 1 month|[2,*] Over :count months', $inputs['billableMonths']),
                                            'hourly' => __('Over :hours hours per month', ['hours' => \Illuminate\Support\Number::format($inputs['hoursPerMonth'] / 100, maxPrecision: 2, locale: app()->getLocale())]),
                                        } }}
                                    </dd>
                                @endif
                            </div>
                        @endforeach
                    </dl>

                    <div class="mt-4 space-y-1 text-sm text-gray-600 dark:text-gray-400">
                        <p>{{ __('Annual = (annual income target + annual costs) ÷ (1 − reserve % ÷ 100).') }}</p>
                        <p>{{ __('Monthly = annual ÷ billable months. Hourly = monthly ÷ hours per billable month.') }}</p>
                        <p>{{ __('Each amount is rounded up to the nearest cent from its exact value.') }}</p>
                    </div>
                </x-filament::section>

                @if (($bar = $this->breakdownBar()) !== null)
                    <x-filament::section :heading="__('How this is calculated')" class="mt-6">
                        {{ $bar }}

                        <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">
                            ({{ $formatMinimum($inputs['incomeGoal']) }} + {{ $formatMinimum($inputs['costs']) }})
                            ÷ (1 − {{ \Illuminate\Support\Number::format($inputs['reservePercent'] / 100, maxPrecision: 2, locale: app()->getLocale()) }}%)
                            = {{ __(':amount per year.', ['amount' => $formatMinimum($result['annual'])]) }}
                        </p>
                    </x-filament::section>
                @endif

                @if (($comparison = $this->comparison()) !== null)
                    <x-filament::section :heading="__('Compared with what you currently charge')" class="mt-6">
                        <dl class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1.5 text-sm">
                            <dt class="text-gray-600 dark:text-gray-300">{{ __('Revenue so far in :year', ['year' => $comparison['year']]) }}</dt>
                            <dd class="text-end font-medium tabular-nums text-gray-950 dark:text-white">{{ $comparison['billed'] }}</dd>

                            @if ($comparison['pace'] !== null)
                                <dt class="text-gray-600 dark:text-gray-300">{{ __('Monthly pace needed through December') }}</dt>
                                <dd class="text-end font-medium tabular-nums text-gray-950 dark:text-white">{{ __(':amount per month', ['amount' => $comparison['pace']]) }}</dd>
                            @endif
                        </dl>

                        <span class="fap-progress mt-3" role="img" aria-label="{{ __(':percent% of the annual target', ['percent' => $comparison['percent']]) }}">
                            <i style="width: {{ $comparison['percent'] }}%"></i>
                        </span>

                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                            @if ($comparison['reached'])
                                {{ __('You have already billed the annual minimum for this simulation.') }}
                            @else
                                {{ __('You have reached :percent% of the annual minimum in this simulation, based on issued tax documents.', ['percent' => $comparison['percent']]) }}
                            @endif
                        </p>
                    </x-filament::section>
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
