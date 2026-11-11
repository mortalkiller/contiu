@use(App\Filament\Pages\SocialSecurity)
@use(App\Support\CurrentAccount)
@use(App\Support\Money)

@php
    $locale = app()->getLocale();
    $monthLabel = fn (\Carbon\CarbonImmutable $month): string => $locale === 'pt_PT'
        ? SocialSecurity::monthLabel($month)
        : $month->locale($locale)->translatedFormat('F Y');
    $dateTime = $locale === 'pt_PT' ? 'd/m/Y H:i' : 'm/d/Y H:i';
    $date = $locale === 'pt_PT' ? 'd/m/Y' : 'm/d/Y';
    $declaration = $declarations->first();
    $isLate = $declaration === null && today()->gt($declarationDueOn);
@endphp

<x-filament-panels::page>
    @include('filament.pages.partials.steps', [
        'heading' => __('This quarter\'s cycle'),
        'aside' => __('You receive, declare, then pay for three months'),
        'steps' => $steps,
    ])

    {{-- As in the mock: what came in, what was declared and the three contributions, side by side. --}}
    <div class="grid items-start gap-6 xl:grid-cols-3">
        <x-filament::section :heading="__('Recorded payments')" :description="__('By payment date, including those marked exempt. A reference for Segurança Social Direta.')">
            <dl class="grid gap-1">
                <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Total recorded') }}</dt>
                <dd class="text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ Money::format($receipts->sum('amount'), $locale) }}</dd>
                @if ($receipts->sum('amount') !== $estimateIncome)
                    <dd class="text-sm text-gray-500 dark:text-gray-400">{{ __(':amount used in the estimate', ['amount' => Money::format($estimateIncome, $locale)]) }}</dd>
                @endif
            </dl>

            @if ($receipts->isEmpty())
                <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">{{ __('No payments recorded this quarter.') }}</p>
            @else
                <ul class="-mx-6 mt-4 divide-y divide-gray-200 border-t border-gray-200 text-sm dark:divide-white/10 dark:border-white/10">
                    @foreach ($receipts as $receipt)
                        <li class="flex items-start justify-between gap-3 px-6 py-3">
                            <span class="min-w-0">
                                <span class="block font-medium text-gray-950 dark:text-white">{{ $receipt->receivableLabels() ?: __('Payment #:id', ['id' => $receipt->id]) }}</span>
                                <span class="block text-gray-500 dark:text-gray-400">
                                    {{ $receipt->paid_on->format($date) }}
                                    @if ($receipt->tax_exempt) {{ __('· Marked exempt in Contiu') }} @endif
                                </span>
                            </span>
                            <span class="whitespace-nowrap tabular-nums">{{ Money::format($receipt->amount, $locale) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('Declared income')">
            <x-slot name="afterHeader">
                <x-filament::badge :color="$declaration ? 'success' : ($isLate ? 'danger' : 'warning')">
                    {{ $declaration ? __('Declaration recorded') : __('To declare') }}
                </x-filament::badge>
            </x-slot>

            <div class="space-y-4 text-sm">
                @if ($declaration)
                    <dl class="grid gap-2">
                        @foreach ($incomeMonths as $month)
                            <div class="flex justify-between gap-3">
                                <dt class="text-gray-600 dark:text-gray-400">{{ $monthLabel($month) }}</dt>
                                <dd class="tabular-nums">{{ Money::format($declaration->amounts[$month->format('Y-m')], $locale) }}</dd>
                            </div>
                        @endforeach
                        <div class="flex justify-between gap-3 border-t border-gray-200 pt-2 font-semibold dark:border-white/10">
                            <dt>{{ __('Total declared') }}</dt>
                            <dd class="tabular-nums">{{ Money::format(array_sum($declaration->amounts), $locale) }}</dd>
                        </div>
                    </dl>
                    <p class="text-gray-500 dark:text-gray-400">{{ __('Recorded locally on :date', ['date' => $declaration->recorded_at->format($dateTime)]) }}</p>
                @else
                    <p>
                        {{ $isLate
                            ? __('The quarterly declaration was due by :date. File it in Segurança Social Direta and record here what you declared.', ['date' => $declarationDueOn->format($date)])
                            : __('The quarterly declaration is due by :date. File it in Segurança Social Direta and record here what you declared.', ['date' => $declarationDueOn->format($date)]) }}
                    </p>
                    <dl class="flex justify-between gap-3">
                        <dt class="text-gray-600 dark:text-gray-400">{{ __('Amount to declare, from your records') }}</dt>
                        <dd class="font-semibold tabular-nums">{{ Money::format($estimateIncome, $locale) }}</dd>
                    </dl>
                @endif

                @if (! CurrentAccount::readOnly())
                    <div>{{ $this->recordDeclarationAction }}</div>
                @endif

                @if ($declarations->count() > 1)
                    <details>
                        <summary class="cursor-pointer font-medium">{{ __('Declaration history') }}</summary>
                        <ul class="mt-2 space-y-2">
                            @foreach ($declarations->skip(1) as $revision)
                                <li>
                                    {{ $revision->recorded_at->format($dateTime) }}
                                    @foreach ($incomeMonths as $month)
                                        · {{ $monthLabel($month) }} {{ Money::format($revision->amounts[$month->format('Y-m')], $locale) }}
                                    @endforeach
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('Monthly contributions')">
            <ul class="-mx-6 -mt-2 divide-y divide-gray-200 text-sm dark:divide-white/10">
                @foreach ($contributionMonths as $month)
                    @php
                        $key = $month->format('Y-m');
                        $revisions = $contributionsByMonth->get($key, collect());
                        $confirmed = $revisions->first();
                    @endphp
                    <li class="space-y-2 px-6 py-3">
                        <div class="flex items-start justify-between gap-3">
                            <span class="min-w-0">
                                <span class="block font-medium text-gray-950 dark:text-white">{{ $monthLabel($month) }}</span>
                                <span class="block text-gray-500 dark:text-gray-400">
                                    {{ $confirmed
                                        ? __('Confirmed on :date', ['date' => $confirmed->confirmed_at->format($dateTime)])
                                        : __('Payment in :month', ['month' => $monthLabel($month->addMonth())]).' · '.__('estimate') }}
                                </span>
                            </span>
                            <span class="whitespace-nowrap tabular-nums {{ $confirmed ? 'font-semibold' : '' }}">
                                {{ Money::format($confirmed?->amount ?? $estimatedContributions[$key], $locale) }}
                            </span>
                        </div>
                        @if (! CurrentAccount::readOnly())
                            <div>{{ ($this->recordContributionAction)(['month' => $key]) }}</div>
                        @endif
                        @if ($revisions->count() > 1)
                            <details>
                                <summary class="cursor-pointer font-medium">{{ __('Contribution history') }}</summary>
                                <ul class="mt-2 space-y-1">
                                    @foreach ($revisions->skip(1) as $revision)
                                        <li>{{ $revision->confirmed_at->format($dateTime) }} · {{ Money::format($revision->amount, $locale) }}</li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    </li>
                @endforeach
            </ul>

            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                {!! __('Check the exact amount shown under :amounts in Segurança Social Direta. Payment is handled separately.', ['amounts' => '<strong>'.e(__('Amounts due')).'</strong>']) !!}
                {{ __('The estimate uses your current tax settings.') }}
                @if ($incompleteSettings)
                    {{ __('Complete them for a better estimate.') }}
                @endif
            </p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
