@php
    use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
    use App\Support\Money;
    use App\Support\WithholdingReview;

    $locale = app()->getLocale();
    $date = $locale === 'pt_PT' ? 'd/m/Y' : 'm/d/Y';
@endphp

<x-filament-panels::page>
    @include('filament.pages.partials.tax-summary', ['stats' => $summary])

    {{-- As in the mock: the withholding recorded, with the form to record more beside it. --}}
    <div class="grid items-start gap-6 xl:grid-cols-7">
        <div @class(['xl:col-span-5' => $this::canRecord(), 'xl:col-span-7' => ! $this::canRecord()])>
            <x-filament::section :heading="__('Recorded withholding tax')">
                @if ($review->rows->isEmpty())
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('No withholding tax recorded for this year.') }}</p>
                @else
                    <div class="-mx-6 -mt-2 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-y border-gray-200 bg-gray-50 text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">
                                    <th scope="col" class="px-6 py-2.5 text-start font-medium">{{ __('Document') }}</th>
                                    <th scope="col" class="px-3 py-2.5 text-start font-medium">{{ __('Client') }}</th>
                                    <th scope="col" class="px-3 py-2.5 text-start font-medium">{{ __('Date') }}</th>
                                    <th scope="col" class="px-3 py-2.5 text-end font-medium">{{ __('Total before withholding tax') }}</th>
                                    <th scope="col" class="px-3 py-2.5 text-end font-medium">{{ __('Recorded income tax withheld') }}</th>
                                    <th scope="col" class="px-3 py-2.5 text-end font-medium">{{ __('Received') }}</th>
                                    <th scope="col" class="px-3 py-2.5 pe-6 text-start font-medium">{{ __('Evidence source') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                                @foreach ($review->rows as $row)
                                    @php $record = $row['record']; @endphp
                                    <tr wire:key="withholding-record-{{ $record->id }}" class="align-top">
                                        <td class="px-6 py-3">
                                            <a class="font-medium text-gray-950 underline-offset-2 hover:underline dark:text-white" href="{{ FiscalDocumentResource::getUrl('index', ['tableSearch' => $record->document->number]) }}">{{ $record->document->number }}</a>
                                            @if ($record->isAdvanceEvidence())
                                                <span class="block text-gray-500 dark:text-gray-400">{{ __('Advance payment') }}</span>
                                            @elseif ($record->payment_allocation_id === null)
                                                <span class="block text-gray-500 dark:text-gray-400">{{ __('Tax record · no confirmed allocation') }}</span>
                                            @elseif (! $record->settles_receivable)
                                                <span class="block text-gray-500 dark:text-gray-400">{{ __('Charge already recorded net') }}</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-3">{{ $record->document->party_name ?: '—' }}</td>
                                        <td class="px-3 py-3 whitespace-nowrap">{{ $record->paid_on->format($date) }}</td>
                                        <td class="px-3 py-3 text-end whitespace-nowrap tabular-nums">{{ Money::format($record->gross_amount, $locale) }}</td>
                                        <td class="px-3 py-3 text-end whitespace-nowrap tabular-nums">{{ Money::format($record->retained_amount, $locale) }}</td>
                                        <td class="px-3 py-3 text-end whitespace-nowrap tabular-nums">
                                            {{ Money::format($record->payment_allocation_id ? $row['cashAmount'] : $row['paymentAmount'], $locale) }}
                                            <span class="block text-gray-500 dark:text-gray-400">
                                                {{ $row['bankCredit'] === null ? __('No individual match') : __('Bank :amount', ['amount' => Money::format($row['bankCredit'], $locale)]) }}
                                            </span>
                                        </td>
                                        <td class="px-3 py-3 pe-6">
                                            <x-filament::badge :color="$row['current'] ? 'success' : 'warning'" class="w-fit">
                                                {{ $row['current'] ? __('Verified') : __('Source changed · review needed') }}
                                            </x-filament::badge>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="border-t border-gray-200 bg-gray-50 font-semibold dark:border-white/10 dark:bg-white/5">
                                    <td colspan="4" class="px-6 py-2.5">{{ __('Verified total') }}</td>
                                    <td class="px-3 py-2.5 text-end tabular-nums">{{ $review->retainedTotal === null ? '—' : Money::format($review->retainedTotal, $locale) }}</td>
                                    <td colspan="2" class="pe-6"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        </div>

        @if ($this::canRecord())
            <div class="xl:col-span-2">
                <x-filament::section :heading="$ordinary ? __('Reconcile issued receipt') : ($allocation ? __('Reconcile withholding tax with an issued receipt') : __('Record from a document'))">
                    <div class="space-y-4 text-sm">
                        @if ($allocation)
                            <dl class="grid gap-2">
                                <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-400">{{ __('Charge') }}</dt><dd class="text-end font-medium">{{ $allocation->receivable->label }}</dd></div>
                                <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-400">{{ __('Allocated cash') }}</dt><dd class="tabular-nums">{{ Money::format($allocation->amount, $locale) }}</dd></div>
                                <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-400">{{ __('Payment date') }}</dt><dd>{{ $allocation->payment->paid_on->format($date) }}</dd></div>
                                @if ($preview)
                                    <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-400">{{ __('Invoice') }}</dt><dd>{{ $preview['invoice'] }}</dd></div>
                                    <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-400">{{ __('Gross amount available for this receipt') }}</dt><dd class="tabular-nums">{{ Money::format($preview['availableGross'], $locale) }}</dd></div>
                                @endif
                            </dl>
                        @endif

                        @if ($sources->isEmpty())
                            <p class="text-gray-600 dark:text-gray-300">
                                {{ $previewError ?? ($allocation
                                    ? __('No receipts are eligible for this allocation. Check that the invoice is linked to the charge and the receipt was recorded with the total before withholding tax.')
                                    : __('No receipts or invoice-receipts issued in EUR and linked to payments received this year.')) }}
                            </p>
                        @else
                            <form wire:submit="record" class="space-y-4">
                                @if ($allocation && ! $ordinary)
                                    <p class="text-gray-600 dark:text-gray-300">{{ __('Use the income tax shown on the receipt. The gross amount less withholding tax must equal the allocated cash.') }}</p>
                                @endif
                                <div class="space-y-1">
                                    <label for="withholding-document" class="font-medium">{{ __('Receipt or invoice-receipt') }}</label>
                                    <x-filament::input.wrapper>
                                        <x-filament::input.select id="withholding-document" wire:model.live="documentId" required>
                                            <option value="">{{ __('Select document') }}</option>
                                            @foreach ($sources as $source)
                                                <option value="{{ $source->id }}">{{ $source->number }} · {{ $source->document_date->format($date) }}{{ $source->party_name ? ' · '.$source->party_name : '' }} · {{ __(':amount before withholding tax', ['amount' => Money::format($source->total_amount, $locale)]) }}</option>
                                            @endforeach
                                        </x-filament::input.select>
                                    </x-filament::input.wrapper>
                                    @error('documentId') <p class="text-danger-600" role="alert">{{ $message }}</p> @enderror
                                </div>
                                @if ($selected)
                                    @php
                                        $payment = $allocation?->payment ?? $selected->links->payment;
                                        $bankCredit = WithholdingReview::bankCredit($payment);
                                    @endphp
                                    <dl class="grid gap-2">
                                        <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-400">{{ __('Total before withholding tax') }}</dt><dd class="tabular-nums">{{ Money::format($selected->total_amount, $locale) }}</dd></div>
                                        <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-400">{{ $allocation ? __('Allocated cash') : __('Recorded payment') }}</dt><dd class="tabular-nums">{{ Money::format($allocation?->amount ?? $payment->amount, $locale) }}</dd></div>
                                        <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-400">{{ __('Bank credit for the payment') }}</dt><dd class="tabular-nums">{{ $bankCredit === null ? __('No individual match') : Money::format($bankCredit, $locale) }}</dd></div>
                                    </dl>
                                @endif
                                @unless ($ordinary)
                                    <div class="space-y-1">
                                        <label for="withholding-retained" class="font-medium">{{ __('Income tax withheld as shown on the document') }}</label>
                                        <x-filament::input.wrapper suffix="€">
                                            <x-filament::input id="withholding-retained" type="text" inputmode="decimal" wire:model="retained" required />
                                        </x-filament::input.wrapper>
                                        @error('retained') <p class="text-danger-600" role="alert">{{ $message }}</p> @enderror
                                    </div>
                                @endunless
                                @if ($allocation)
                                    <label class="flex items-start gap-2">
                                        <x-filament::input.checkbox wire:model="accepted" class="mt-0.5" />
                                        {{ $ordinary ? __('I confirm that the receipt matches this allocation and there was no withholding tax.') : __('I confirm that the receipt and withholding tax match this allocation.') }}
                                    </label>
                                    @error('accepted') <p class="text-danger-600" role="alert">{{ $message }}</p> @enderror
                                @endif
                                <x-filament::button type="submit" :color="$allocation ? 'primary' : 'gray'" wire:loading.attr="disabled">
                                    {{ $ordinary ? __('Reconcile receipt') : ($allocation ? __('Confirm link and withholding tax') : __('Record withholding tax')) }}
                                </x-filament::button>
                            </form>
                        @endif
                    </div>
                </x-filament::section>
            </div>
        @endif
    </div>
</x-filament-panels::page>
