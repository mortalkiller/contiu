@php
    use Illuminate\Support\Number;

    $formatMinimum = fn (int $cents): string => $this->formatMinimum($cents);
@endphp

<x-filament-panels::page>
    {{-- As in the mock: your numbers on the left, what they mean on the right. --}}
    <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]">
        <form wire:submit="calculate" class="space-y-4">
            {{ $this->form }}

            <x-filament::button type="submit">
                {{ __('Calculate') }}
            </x-filament::button>
        </form>

        <div aria-live="polite">
            @if ($result === null)
                {{-- The mock's shape before the first calculation: the three cards, waiting for figures. --}}
                @include('filament.pages.partials.tax-summary', ['notice' => null, 'stats' => collect([__('Per year'), __('Per billable month'), __('Per billable hour')])
                    ->map(fn (string $label): array => ['label' => $label, 'value' => '—', 'note' => __('Press Calculate')])->all()])
            @else
                <div wire:dirty.remove class="space-y-6">
                    @include('filament.pages.partials.tax-summary', ['notice' => null, 'stats' => collect(['annual' => __('Per year'), 'monthly' => __('Per billable month'), 'hourly' => __('Per billable hour')])
                        ->map(fn (string $label, string $period): array => [
                            'label' => $label,
                            'value' => $formatMinimum($result[$period]),
                            'note' => $inputs === null ? null : match ($period) {
                                'annual' => __('Required revenue'),
                                'monthly' => trans_choice('{1} Over 1 month|[2,*] Over :count months', $inputs['billableMonths']),
                                'hourly' => __('Over :hours hours per month', ['hours' => Number::format($inputs['hoursPerMonth'] / 100, maxPrecision: 2, locale: app()->getLocale())]),
                            },
                        ])->values()->all()])

                    @if (($bar = $this->breakdownBar()) !== null)
                        <x-filament::section :heading="__('How this is calculated')">
                            {{ $bar }}

                            <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">
                                ({{ $formatMinimum($inputs['incomeGoal']) }} + {{ $formatMinimum($inputs['costs']) }})
                                ÷ (1 − {{ Number::format($inputs['reservePercent'] / 100, maxPrecision: 2, locale: app()->getLocale()) }}%)
                                = {{ __(':amount per year.', ['amount' => $formatMinimum($result['annual'])]) }}
                            </p>
                            <div class="mt-2 space-y-1 text-xs text-gray-500 dark:text-gray-400">
                                <p>{{ __('Monthly = annual ÷ billable months. Hourly = monthly ÷ hours per billable month.') }}</p>
                                <p>{{ __('Each amount is rounded up to the nearest cent from its exact value.') }}</p>
                            </div>
                        </x-filament::section>
                    @endif

                    @if (($comparison = $this->comparison()) !== null)
                        <x-filament::section :heading="__('Compared with what you currently charge')">
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
    </div>
</x-filament-panels::page>
