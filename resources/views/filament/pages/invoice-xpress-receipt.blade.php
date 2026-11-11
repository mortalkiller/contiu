<x-filament-panels::page>
    @unless ($canWrite)
        <x-filament::section>
            {{ __('Receipt issuing is disabled for this InvoiceXpress account.') }}
        </x-filament::section>
    @endunless

    @error('receipt')
        <p role="alert" class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
    @enderror

    @if ($previewData !== null)
        <x-filament::section>
            <x-slot name="heading">{{ __('Confirm receipt issuing') }}</x-slot>

            <form wire:submit="confirm" class="space-y-4">
                <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Original invoice') }}</dt>
                        <dd>{{ $previewData['invoice'] }} · {{ $previewData['charge'] }}</dd>
                        <dd class="text-sm text-gray-500 dark:text-gray-400">{{ __('Receipt series :series', ['series' => $previewData['series']]) }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Payment received') }}</dt>
                        <dd>#{{ $previewData['paymentId'] }} · {{ __('Bank transfer') }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Payment date') }}</dt>
                        <dd>{{ $previewData['paidOn'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Amount to issue') }}</dt>
                        <dd class="font-semibold">{{ \App\Support\Money::format($previewData['amount'], app()->getLocale()) }}</dd>
                    </div>
                </dl>

                <label class="flex items-center gap-2 text-sm">
                    <x-filament::input.checkbox wire:model="accepted" />
                    {{ __('I confirm this payment was received without withholding tax and I want to issue the receipt.') }}
                </label>
                @error('accepted')
                    <p role="alert" class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                @enderror

                <x-filament::button type="submit" wire:loading.attr="disabled">
                    {{ __('Issue receipt') }}
                </x-filament::button>
            </form>
        </x-filament::section>
    @endif

    {{ $this->table }}

    @if ($attempts->isNotEmpty())
        <x-filament::section>
            <x-slot name="heading">{{ __('Issuing history') }}</x-slot>

            <ul class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($attempts as $attempt)
                    <li wire:key="receipt-attempt-{{ $attempt->id }}" class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div>
                            <p class="text-sm font-medium">
                                #{{ $attempt->id }} · {{ __('Invoice :number', ['number' => $attempt->intent['local']['invoiceNumber']]) }}
                                · {{ \App\Support\Money::format($attempt->intent['local']['amount'], app()->getLocale()) }}
                            </p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                @if ($attempt->provider_receipt_id !== null)
                                    {{ __('Receipt :number', ['number' => $attempt->receipt_number]) }} · ATCUD {{ $attempt->receipt_atcud }}
                                    @if ($attempt->fiscal_document_id === null)
                                        · {{ __('Local record removed') }}
                                    @endif
                                @else
                                    {{ __('Pending verification · :note', ['note' => $attempt->note]) }}
                                @endif
                            </p>
                        </div>

                        @if ($attempt->provider_receipt_id === null)
                            <x-filament::button color="gray" size="sm" wire:click="recover({{ $attempt->id }})" wire:loading.attr="disabled">
                                {{ __('Check in InvoiceXpress') }}
                            </x-filament::button>
                        @endif
                    </li>
                @endforeach
            </ul>

            {{ $attempts->links() }}
        </x-filament::section>
    @endif
</x-filament-panels::page>
