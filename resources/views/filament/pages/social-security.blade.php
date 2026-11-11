@use(App\Filament\Pages\SocialSecurity)
@use(App\Support\CurrentAccount)
@use(App\Support\Money)

@php
    $monthLabel = fn (\Carbon\CarbonImmutable $month): string => app()->getLocale() === 'pt_PT'
        ? SocialSecurity::monthLabel($month)
        : $month->locale(app()->getLocale())->translatedFormat('F Y');
@endphp

<x-filament-panels::page>
    @include('filament.pages.partials.tax-summary', ['stats' => $summary])

    <form method="GET" action="{{ SocialSecurity::getUrl() }}" class="flex flex-wrap items-end gap-3">
        <div class="space-y-1.5">
            <label for="ss-quarter" class="block text-sm font-medium">{{ __('Quarter closed') }}</label>
            <x-filament::input.wrapper>
                <x-filament::input.select id="ss-quarter" name="quarter">
                    @foreach ($quarters as $option)
                        <option value="{{ $option }}" @selected($option === $this->quarter)>{{ __('Q:quarter :year', ['quarter' => substr($option, -1), 'year' => substr($option, 0, 4)]) }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
        <x-filament::button type="submit" color="gray">{{ __('View quarter') }}</x-filament::button>
    </form>

    <x-filament::section :heading="__('Recorded payments')">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('Amounts recorded in Contiu by payment date, including those marked exempt. Use them as a reference when checking income excluding VAT in Segurança Social Direta.') }}
        </p>

        <dl class="my-4 grid gap-3 sm:grid-cols-2">
            <div>
                <dt class="text-sm">{{ __('Total recorded') }}</dt>
                <dd class="font-semibold tabular-nums">{{ Money::format($receipts->sum('amount'), app()->getLocale()) }}</dd>
            </div>
            <div>
                <dt class="text-sm">{{ __('Payments used in the estimate') }}</dt>
                <dd class="font-semibold tabular-nums">{{ Money::format($estimateIncome, app()->getLocale()) }}</dd>
            </div>
        </dl>

        @if ($receipts->isEmpty())
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('No payments recorded this quarter.') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-start">
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <th scope="col" class="py-2 text-start font-medium">{{ __('Date') }}</th>
                            <th scope="col" class="px-3 py-2 text-start font-medium">{{ __('Payment received') }}</th>
                            <th scope="col" class="py-2 text-end font-medium">{{ __('Value') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($receipts as $receipt)
                            <tr>
                                <td class="py-2 whitespace-nowrap">{{ $receipt->paid_on->format(app()->getLocale() === 'pt_PT' ? 'd/m/Y' : 'm/d/Y') }}</td>
                                <td class="px-3 py-2">
                                    {{ $receipt->receivableLabels() ?: __('Payment #:id', ['id' => $receipt->id]) }}
                                    @if ($receipt->tax_exempt)
                                        <span class="text-xs text-gray-600 dark:text-gray-400"> {{ __('· Marked exempt in Contiu') }}</span>
                                    @endif
                                </td>
                                <td class="py-2 text-end whitespace-nowrap tabular-nums">{{ Money::format($receipt->amount, app()->getLocale()) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section :heading="__('Declared income')">
        <x-slot name="afterHeader">
            @if (! CurrentAccount::readOnly())
                {{ $this->recordDeclarationAction }}
            @endif
        </x-slot>

        @php
            $declaration = $declarations->first();
        @endphp
        @if ($declaration)
            <dl class="grid gap-3 sm:grid-cols-4">
                @foreach ($incomeMonths as $month)
                    <div>
                        <dt class="text-sm">{{ $monthLabel($month) }}</dt>
                        <dd class="font-semibold tabular-nums">{{ Money::format($declaration->amounts[$month->format('Y-m')], app()->getLocale()) }}</dd>
                    </div>
                @endforeach
                <div>
                    <dt class="text-sm">{{ __('Total declared') }}</dt>
                    <dd class="font-semibold tabular-nums">{{ Money::format(array_sum($declaration->amounts), app()->getLocale()) }}</dd>
                </div>
            </dl>
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">{{ __('Recorded locally on :date', ['date' => $declaration->recorded_at->format(app()->getLocale() === 'pt_PT' ? 'd/m/Y H:i' : 'm/d/Y H:i')]) }}</p>
        @else
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('No declared amounts have been recorded for this quarter yet.') }}</p>
        @endif

        @if ($declarations->count() > 1)
            <details class="mt-4 text-sm">
                <summary class="cursor-pointer font-medium">{{ __('Declaration history') }}</summary>
                <ul class="mt-2 space-y-2">
                    @foreach ($declarations->skip(1) as $revision)
                        <li>
                            {{ $revision->recorded_at->format(app()->getLocale() === 'pt_PT' ? 'd/m/Y H:i' : 'm/d/Y H:i') }}
                            @foreach ($incomeMonths as $month)
                                · {{ $monthLabel($month) }} {{ Money::format($revision->amounts[$month->format('Y-m')], app()->getLocale()) }}
                            @endforeach
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif
    </x-filament::section>

    <x-filament::section :heading="__('Monthly contributions')">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {!! __('Check the exact amount shown under :amounts in Segurança Social Direta. Payment is handled separately.', ['amounts' => '<strong>'.e(__('Amounts due')).'</strong>']) !!}
        </p>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
            {{ __('The estimate uses your current tax settings.') }}
            @if ($incompleteSettings)
                {{ __('Complete them for a better estimate.') }}
            @endif
        </p>

        <div class="mt-4 grid gap-3 lg:grid-cols-3">
            @foreach ($contributionMonths as $month)
                @php
                    $key = $month->format('Y-m');
                    $revisions = $contributionsByMonth->get($key, collect());
                    $confirmed = $revisions->first();
                @endphp

                <div class="space-y-3 rounded-lg border border-gray-200 p-4 dark:border-white/10">
                    <div>
                        <h3 class="text-sm font-semibold">{{ $monthLabel($month) }}</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Payment in :month', ['month' => $monthLabel($month->addMonth())]) }}</p>
                    </div>
                    <dl class="space-y-1 text-sm">
                        <div class="flex justify-between gap-3">
                            <dt>{{ __('Estimate') }}</dt>
                            <dd class="tabular-nums">{{ Money::format($estimatedContributions[$key], app()->getLocale()) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt>{{ __('Confirmed amount') }}</dt>
                            <dd class="font-semibold tabular-nums">{{ $confirmed ? Money::format($confirmed->amount, app()->getLocale()) : __('Pending confirmation') }}</dd>
                        </div>
                    </dl>
                    @if (! CurrentAccount::readOnly())
                        {{ ($this->recordContributionAction)(['month' => $key]) }}
                    @endif
                    @if ($confirmed)
                        <p class="text-xs text-gray-600 dark:text-gray-400">{{ __('Confirmed on :date', ['date' => $confirmed->confirmed_at->format(app()->getLocale() === 'pt_PT' ? 'd/m/Y H:i' : 'm/d/Y H:i')]) }}</p>
                    @endif
                    @if ($revisions->count() > 1)
                        <details class="text-sm">
                            <summary class="cursor-pointer font-medium">{{ __('Contribution history') }}</summary>
                            <ul class="mt-2 space-y-1">
                                @foreach ($revisions->skip(1) as $revision)
                                    <li>{{ $revision->confirmed_at->format(app()->getLocale() === 'pt_PT' ? 'd/m/Y H:i' : 'm/d/Y H:i') }} · {{ Money::format($revision->amount, app()->getLocale()) }}</li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
