<x-filament-panels::page>
    @include('filament.pages.partials.tax-summary', ['stats' => [], 'notice' => ['tone' => 'warning', ...$summaryNotice]])

    {{-- As in the mock: the scenario on the left, what it changes on the right. --}}
    <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,24rem)_minmax(0,1fr)]">
    <form wire:submit="compare" class="space-y-4">
        {{ $this->form }}

        <x-filament::button type="submit">{{ __('Compare') }}</x-filament::button>
    </form>

    <div class="space-y-6" aria-live="polite">
        @include('filament.pages.partials.tax-summary', ['stats' => $summary, 'notice' => null])

        @if ($result !== null)
            <div wire:dirty.remove>
                <x-filament::section :heading="__('Comparison for :year', ['year' => $result['year']])">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-gray-600 dark:text-gray-400">
                                    <th scope="col" class="p-2 text-left">{{ __('Item') }}</th>
                                    <th scope="col" class="p-2 text-end">{{ __('Documented base') }}</th>
                                    <th scope="col" class="p-2 text-end">{{ __('New retainer') }}</th>
                                    <th scope="col" class="p-2 text-end">{{ __('Base + retainer') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 tabular-nums dark:divide-gray-700">
                                @foreach ([
                                    [__('Revenue excluding VAT'), 'baselineBilling', 'retainerBilling', 'scenarioBilling'],
                                    [__('Tax withheld'), 'baselineRetention', 'scenarioRetention', 'combinedRetention'],
                                ] as [$label, $baseline, $retainer, $total])
                                    <tr>
                                        <th scope="row" class="p-2 text-left font-medium">{{ $label }}</th>
                                        <td class="p-2 text-end">{{ $result[$baseline] === null ? __('No documented data') : \App\Support\Money::format($result[$baseline], app()->getLocale()) }}</td>
                                        <td class="p-2 text-end">{{ $result[$retainer] === null ? __('Not specified') : \App\Support\Money::format($result[$retainer], app()->getLocale()) }}</td>
                                        <td class="p-2 text-end font-semibold">{{ $result[$total] === null ? __('Missing data') : \App\Support\Money::format($result[$total], app()->getLocale()) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-400">
                        <p>{{ trans_choice('{1} Additional retainer of :amount per month × 1 month in the year.|[2,*] Additional retainer of :amount per month × :count months in the year.', $result['months'], ['amount' => \App\Support\Money::format($result['monthlyNet'], app()->getLocale())]) }}</p>
                        <p>{{ __('Documented revenue uses active invoices and invoice-receipts issued in EUR, less credit notes, by document date.') }}</p>
                        <p>{{ __('Documented withholding tax totals confirmed records that are still valid, by year of receipt. Retainer withholding tax comes from the amount you entered.') }}</p>
                        @if ($result['foreignDocuments'] > 0)
                            <p>{{ trans_choice('{1} 1 document in another currency was excluded from the base.|[2,*] :count documents in other currencies were excluded from the base.', $result['foreignDocuments']) }}</p>
                        @endif
                        @if ($result['staleRetentionRecords'] > 0)
                            <p>{{ trans_choice('{1} 1 withholding record needing confirmation again was excluded from the total.|[2,*] :count withholding records needing confirmation again were excluded from the total.', $result['staleRetentionRecords']) }}</p>
                        @endif
                    </div>
                </x-filament::section>
                @if ($result['settlement'] !== null)
                    <x-filament::section :heading="__('2026 personal income tax estimate')">
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr>
                                        <th scope="col" class="p-2 text-start">{{ __('Item') }}</th>
                                        <th scope="col" class="p-2 text-end">{{ __('Current scenario') }}</th>
                                        <th scope="col" class="p-2 text-end">{{ __('With the retainer') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 tabular-nums dark:divide-gray-700">
                                    @foreach (['grossTax' => __('Tax at the general rates'), 'solidarity' => __('Solidarity surcharge'), 'taxAfterDeductions' => __('Tax after deductions'), 'payable' => __('Estimated income tax payable'), 'refund' => __('Estimated refund')] as $key => $label)
                                        <tr>
                                            <th scope="row" class="p-2 text-start font-medium">{{ $label }}</th>
                                            <td class="p-2 text-end">{{ \App\Support\Money::format($result['settlement']['baseline'][$key], app()->getLocale()) }}</td>
                                            <td class="p-2 text-end">{{ \App\Support\Money::format($result['settlement']['combined'][$key], app()->getLocale()) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">
                            {{ __('Uses the values entered for each scenario and applies the minimum payment and refund thresholds.') }}
                        </p>
                    </x-filament::section>
                @endif
            </div>
        @endif
    </div>
    </div>
</x-filament-panels::page>
