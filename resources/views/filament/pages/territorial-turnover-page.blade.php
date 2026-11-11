<x-filament-panels::page>
    <x-filament::section>
        <div class="flex flex-wrap items-start gap-8">
            <div>
                <label for="territorial-turnover-year" class="text-sm font-medium">{{ __('Year') }}</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="territorial-turnover-year" wire:model.live="year">
                        @foreach ($years as $year)
                            <option value="{{ $year }}">{{ $year }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
            <div>
                <p class="text-sm font-medium">{{ $report->subtotalLabel() }}</p>
                <p class="text-xl font-semibold">{{ $report->subtotal() === null ? __('No confirmed amount') : \App\Support\Money::format($report->subtotal(), app()->getLocale()) }}</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $report->summary() }}</p>
            </div>
        </div>
        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
            {{ __('Active issued invoices, invoice-receipts and credit notes are reviewed. If the transaction date is unknown, the document stays pending review until it is assigned to a year.') }}
        </p>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
