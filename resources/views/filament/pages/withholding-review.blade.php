@php
    use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
    use App\Support\Money;
    use App\Support\WithholdingReview;
@endphp

<x-filament-panels::page>
    @include('filament.pages.partials.tax-summary', ['stats' => $summary])

    <x-filament::section :heading="__('Documented income tax withheld')">
        <div class="space-y-4">
            @if ($allocation)
                <dl class="grid gap-3 text-sm sm:grid-cols-3">
                    <div><dt>{{ __('Charge') }}</dt><dd class="font-semibold">{{ $allocation->receivable->label }}</dd></div>
                    <div><dt>{{ __('Allocated cash') }}</dt><dd class="font-semibold">{{ Money::format($allocation->amount, app()->getLocale()) }}</dd></div>
                    <div><dt>{{ __('Payment date') }}</dt><dd class="font-semibold">{{ $allocation->payment->paid_on->format(app()->getLocale() === 'pt_PT' ? 'd/m/Y' : 'm/d/Y') }}</dd></div>
                    @if ($preview)
                        <div><dt>{{ __('Invoice') }}</dt><dd class="font-semibold">{{ $preview['invoice'] }}</dd></div>
                        <div><dt>{{ __('Gross amount available for this receipt') }}</dt><dd class="font-semibold">{{ Money::format($preview['availableGross'], app()->getLocale()) }}</dd></div>
                    @endif
                </dl>
            @else
                <div class="max-w-40 space-y-1">
                    <label for="withholding-year" class="text-sm font-medium">{{ __('Year of receipt') }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input id="withholding-year" type="number" min="1900" max="9998" wire:model.live.change="year" />
                    </x-filament::input.wrapper>
                </div>
            @endif

            <dl>
                <dt class="text-sm text-gray-600 dark:text-gray-300">{{ __('Total withheld with verified source') }}</dt>
                <dd class="text-lg font-semibold">{{ $review->retainedTotal === null ? __('No confirmed amounts') : Money::format($review->retainedTotal, app()->getLocale()) }}</dd>
            </dl>
        </div>
    </x-filament::section>

    @if ($this::canRecord())
        <x-filament::section :heading="$ordinary ? __('Reconcile issued receipt') : ($allocation ? __('Reconcile withholding tax with an issued receipt') : __('Record from a document'))">
            @if ($sources->isEmpty())
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    {{ $previewError ?? ($allocation
                        ? __('No receipts are eligible for this allocation. Check that the invoice is linked to the charge and the receipt was recorded with the total before withholding tax.')
                        : __('No receipts or invoice-receipts issued in EUR and linked to payments received this year.')) }}
                </p>
            @else
                <form wire:submit="record" class="space-y-4">
                    @if ($allocation && ! $ordinary)
                        <p class="text-sm text-gray-600 dark:text-gray-300">
                            {{ __('Use the income tax shown on the receipt. The gross amount less withholding tax must equal the allocated cash.') }}
                        </p>
                    @endif
                    <div class="space-y-1">
                        <label for="withholding-document" class="text-sm font-medium">{{ __('Receipt or invoice-receipt') }}</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="withholding-document" wire:model.live="documentId" required>
                                <option value="">{{ __('Select document') }}</option>
                                @foreach ($sources as $source)
                                    <option value="{{ $source->id }}">{{ $source->number }} · {{ $source->document_date->format(app()->getLocale() === 'pt_PT' ? 'd/m/Y' : 'm/d/Y') }}{{ $source->party_name ? ' · '.$source->party_name : '' }} · {{ __(':amount before withholding tax', ['amount' => Money::format($source->total_amount, app()->getLocale())]) }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                        @error('documentId') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    </div>
                    @if ($selected)
                        @php
                            $payment = $allocation?->payment ?? $selected->links->payment;
                            $bankCredit = WithholdingReview::bankCredit($payment);
                        @endphp
                        <dl class="grid gap-3 text-sm sm:grid-cols-3">
                            <div><dt>{{ __('Total before withholding tax') }}</dt><dd class="font-semibold">{{ Money::format($selected->total_amount, app()->getLocale()) }}</dd></div>
                            <div><dt>{{ $allocation ? __('Allocated cash') : __('Recorded payment') }}</dt><dd class="font-semibold">{{ Money::format($allocation?->amount ?? $payment->amount, app()->getLocale()) }}</dd></div>
                            <div><dt>{{ __('Bank credit for the payment') }}</dt><dd class="font-semibold">{{ $bankCredit === null ? __('No individual match') : Money::format($bankCredit, app()->getLocale()) }}</dd></div>
                        </dl>
                    @endif
                    @unless ($ordinary)
                        <div class="space-y-1">
                            <label for="withholding-retained" class="text-sm font-medium">{{ __('Income tax withheld as shown on the document') }}</label>
                            <x-filament::input.wrapper suffix="€">
                                <x-filament::input id="withholding-retained" type="text" inputmode="decimal" wire:model="retained" required />
                            </x-filament::input.wrapper>
                            @error('retained') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                        </div>
                    @endunless
                    @if ($allocation)
                        <label class="flex items-center gap-2 text-sm">
                            <x-filament::input.checkbox wire:model="accepted" />
                            {{ $ordinary ? __('I confirm that the receipt matches this allocation and there was no withholding tax.') : __('I confirm that the receipt and withholding tax match this allocation.') }}
                        </label>
                        @error('accepted') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    @endif
                    <x-filament::button type="submit" wire:loading.attr="disabled">
                        {{ $ordinary ? __('Reconcile receipt') : ($allocation ? __('Confirm link and withholding tax') : __('Record withholding tax')) }}
                    </x-filament::button>
                </form>
            @endif
        </x-filament::section>
    @endif

    <x-filament::section :heading="__('Recorded withholding tax')">
        <ul class="divide-y divide-gray-200 dark:divide-white/10">
            @forelse ($review->rows as $row)
                @php($record = $row['record'])
                <li wire:key="withholding-record-{{ $record->id }}" class="space-y-2 py-3 text-sm">
                    <div class="flex flex-wrap items-center gap-2">
                        <a class="font-semibold underline" href="{{ FiscalDocumentResource::getUrl('index', ['tableSearch' => $record->document->number]) }}">{{ $record->document->number }}</a>
                        <span>{{ $record->paid_on->format(app()->getLocale() === 'pt_PT' ? 'd/m/Y' : 'm/d/Y') }}</span>
                        @if (! $row['current'])
                            <x-filament::badge color="warning">{{ __('Source changed · review needed') }}</x-filament::badge>
                        @elseif ($record->isAdvanceEvidence())
                            <x-filament::badge color="gray">{{ __('Advance payment') }}</x-filament::badge>
                        @elseif ($record->payment_allocation_id === null)
                            <x-filament::badge color="gray">{{ __('Tax record · no confirmed allocation') }}</x-filament::badge>
                        @elseif (! $record->settles_receivable)
                            <x-filament::badge color="gray">{{ __('Charge already recorded net') }}</x-filament::badge>
                        @endif
                    </div>
                    <dl class="grid gap-3 sm:grid-cols-4">
                        <div><dt>{{ __('Total before withholding tax') }}</dt><dd class="font-semibold">{{ Money::format($record->gross_amount, app()->getLocale()) }}</dd></div>
                        <div><dt>{{ __('Recorded income tax withheld') }}</dt><dd class="font-semibold">{{ Money::format($record->retained_amount, app()->getLocale()) }}</dd></div>
                        <div><dt>{{ $record->payment_allocation_id ? __('Allocated cash') : __('Recorded payment') }}</dt><dd class="font-semibold">{{ Money::format($record->payment_allocation_id ? $row['cashAmount'] : $row['paymentAmount'], app()->getLocale()) }}</dd></div>
                        <div><dt>{{ __('Bank credit for the payment') }}</dt><dd class="font-semibold">{{ $row['bankCredit'] === null ? __('No individual match') : Money::format($row['bankCredit'], app()->getLocale()) }}</dd></div>
                    </dl>
                </li>
            @empty
                <li class="text-sm text-gray-600 dark:text-gray-300">{{ __('No withholding tax recorded for this year.') }}</li>
            @endforelse
        </ul>
    </x-filament::section>
</x-filament-panels::page>
