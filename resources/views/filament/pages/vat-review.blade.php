@php
    use App\Enums\FiscalDocumentDirection;
    use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
    use App\Support\Money;

    $locale = app()->getLocale();
    $date = $locale === 'pt_PT' ? 'd/m/Y' : 'm/d/Y';
    $balance = $review->confirmedBalance();
    $statusBadge = [
        'supported' => ['success', __('Included in the sum')],
        'unresolved' => ['warning', __('Needs review')],
        'excluded' => ['gray', __('Left out of the sum')],
    ];
    $directions = [
        FiscalDocumentDirection::Issued->value => __('Issued documents'),
        FiscalDocumentDirection::Received->value => __('Received documents'),
    ];
@endphp

<x-filament-panels::page>
    {{-- The period itself is chosen in the header; its name stays on the page for reference. --}}
    <p class="sr-only">{{ $periodLabel }}</p>

    @include('filament.pages.partials.tax-summary', ['stats' => $summary, 'notice' => $summaryNotice])

    {{-- As in the mock: the documents of the period, with the assessment beside them. --}}
    <div class="grid items-start gap-6 xl:grid-cols-7">
        <div class="xl:col-span-5">
            <x-filament::section :heading="__('VAT shown on documents')">
                <x-slot name="afterHeader">
                    <x-filament::button.group role="group" :aria-label="__('Direction')">
                        @foreach ($directions as $value => $label)
                            <x-filament::button size="sm" :color="$direction === $value ? 'primary' : 'gray'" :outlined="$direction !== $value"
                                wire:click="$set('direction', '{{ $value }}')" :aria-pressed="$direction === $value ? 'true' : 'false'">
                                {{ $label }}
                            </x-filament::button>
                        @endforeach
                    </x-filament::button.group>
                </x-slot>

                @foreach (array_intersect_key($directions, [$direction => true]) as $value => $label)
                    @php
                        $rows = $review->documents->filter(fn (array $row): bool => $row['document']->direction->value === $value);
                        $included = $rows->where('status', 'supported')->filter(fn (array $row): bool => $row['document']->currency === 'EUR');
                    @endphp
                    <div>
                        @if ($rows->isEmpty())
                            <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('No documents in this period.') }}</p>
                        @else
                            <div class="-mx-6 -mt-2 overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="border-y border-gray-200 bg-gray-50 text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">
                                            <th scope="col" class="px-6 py-2.5 text-start font-medium">{{ __('Document') }}</th>
                                            <th scope="col" class="px-3 py-2.5 text-start font-medium">{{ __('Date') }}</th>
                                            <th scope="col" class="px-3 py-2.5 text-start font-medium">{{ __('Counterparty') }}</th>
                                            <th scope="col" class="px-3 py-2.5 text-end font-medium">{{ __('Net') }}</th>
                                            <th scope="col" class="px-3 py-2.5 text-end font-medium">{{ __('VAT') }}</th>
                                            <th scope="col" class="px-3 py-2.5 pe-6 text-start font-medium">{{ __('Status') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                                        @foreach ($rows as ['document' => $document, 'status' => $status, 'reason' => $reason])
                                            @php $isEuro = $document->currency === 'EUR'; @endphp
                                            <tr wire:key="vat-document-{{ $document->id }}" class="align-top">
                                                <td class="px-6 py-3">
                                                    <a class="font-medium text-gray-950 underline-offset-2 hover:underline dark:text-white" href="{{ FiscalDocumentResource::getUrl('index', ['tableSearch' => $document->number]) }}">{{ $document->number }}</a>
                                                    <span class="block text-gray-500 dark:text-gray-400">{{ $document->type->getLabel() }}</span>
                                                </td>
                                                <td class="px-3 py-3 whitespace-nowrap">{{ $document->document_date->format($date) }}</td>
                                                <td class="px-3 py-3">{{ $document->party_name ?: '—' }}</td>
                                                <td class="px-3 py-3 text-end whitespace-nowrap tabular-nums">{{ $isEuro ? Money::format($document->net_amount, $locale) : $document->currency }}</td>
                                                <td class="px-3 py-3 text-end whitespace-nowrap tabular-nums">{{ $isEuro ? Money::format($document->vat_amount, $locale) : '—' }}</td>
                                                <td class="px-3 py-3 pe-6">
                                                    <x-filament::badge :color="$statusBadge[$status][0]" class="w-fit">{{ $statusBadge[$status][1] }}</x-filament::badge>
                                                    @if ($status !== 'supported')
                                                        <span class="mt-1 block text-gray-500 dark:text-gray-400">{{ $reason }}</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot>
                                        <tr class="border-t border-gray-200 bg-gray-50 font-semibold dark:border-white/10 dark:bg-white/5">
                                            <td colspan="3" class="px-6 py-2.5">{{ __('Documentary sum') }}</td>
                                            <td class="px-3 py-2.5 text-end tabular-nums">{{ Money::format((int) $included->sum(fn (array $row): int => (int) $row['document']->net_amount), $locale) }}</td>
                                            <td class="px-3 py-2.5 text-end tabular-nums">{{ Money::format((int) $included->sum(fn (array $row): int => (int) $row['document']->vat_amount), $locale) }}</td>
                                            <td class="pe-6"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        @endif
                    </div>
                @endforeach

                @if ($review->documents->isNotEmpty())
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('The total uses the VAT shown on invoices and invoice-receipts in EUR. When VAT becomes due, deductions and adjustments are checked in the assessment.') }}</p>
                @endif
            </x-filament::section>
        </div>

        <div class="xl:col-span-2">
            <x-filament::section :heading="__('Period assessment')">
                <x-slot name="afterHeader">
                    @if ($balance === null)
                        <x-filament::badge color="warning">{{ $review->confirmation === null ? __('Pending confirmation') : __('Documents changed · confirmation needed again') }}</x-filament::badge>
                    @else
                        <x-filament::badge color="success">{{ __('Confirmed') }}</x-filament::badge>
                    @endif
                </x-slot>

                <div class="space-y-4 text-sm">
                    @if ($balance !== null)
                        <p class="text-lg font-semibold">{{ $balance < 0 ? __('Credit balance') : ($balance === 0 ? __('Nothing to pay') : __('Payable')) }} · {{ Money::format(abs($balance), $locale) }}</p>
                        <p>
                            {{ $review->confirmation->reference }}
                            @if ($review->dueOn()) · {{ __('Stated deadline :date', ['date' => $review->dueOn()->format($date)]) }} @endif
                            @if ($review->isPaid()) · {{ __('Paid on :date', ['date' => $review->paidOn()->format($date)]) }} @endif
                        </p>
                        @if (! $this::canConfirm())
                            <dl class="grid gap-2">
                                <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-400">{{ __('Output VAT') }}</dt><dd class="tabular-nums">{{ Money::format($review->confirmation->liquidated, $locale) }}</dd></div>
                                <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-400">{{ __('Deductible VAT') }}</dt><dd class="tabular-nums">{{ Money::format($review->confirmation->deductible, $locale) }}</dd></div>
                                <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-400">{{ __('Net adjustments') }}</dt><dd class="tabular-nums">{{ Money::format($review->confirmation->adjustments, $locale) }}</dd></div>
                            </dl>
                        @endif
                    @endif

                    @if ($this::canConfirm())
                        @php
                            $toCents = fn (string $value): int => preg_match('/\A-?[0-9]{1,9}(?:[,.][0-9]{1,2})?\z/', $value) ? (int) \App\Support\Money::toCents($value) : 0;
                            $result = $toCents($liquidated) - $toCents($deductible) + $toCents($adjustments);
                            $hasFieldErrors = $errors->hasAny(['liquidated', 'deductible', 'adjustments', 'dueOn', 'paidOn']);
                        @endphp
                        <form wire:submit="confirm" class="space-y-4">
                            {{-- As in the mock: the figures, then the reference and the button; the fields to correct them are one click away. --}}
                            <dl class="grid gap-2">
                                <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-400">{{ __('Output VAT') }}</dt><dd class="tabular-nums">{{ Money::format($toCents($liquidated), $locale) }}</dd></div>
                                <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-400">{{ __('Deductible VAT') }}</dt><dd class="tabular-nums">{{ Money::format($toCents($deductible), $locale) }}</dd></div>
                                <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-400">{{ __('Net adjustments') }}</dt><dd class="tabular-nums">{{ Money::format($toCents($adjustments), $locale) }}</dd></div>
                                <div class="flex justify-between gap-3 border-t border-gray-200 pt-2 font-semibold dark:border-white/10">
                                    <dt>{{ __('Result') }}</dt>
                                    <dd class="tabular-nums">{{ $result === 0 ? __('Nothing to pay') : ($result < 0 ? __('Credit balance').' · '.Money::format(abs($result), $locale) : __('Payable').' · '.Money::format($result, $locale)) }}</dd>
                                </div>
                            </dl>

                            <details {!! $hasFieldErrors ? 'open' : '' !!}>
                                <summary class="cursor-pointer font-medium">{{ __('Correct the amounts') }}</summary>
                                <div class="mt-3 space-y-4">
                                    @foreach (['liquidated' => __('Output VAT'), 'deductible' => __('Deductible VAT'), 'adjustments' => __('Net adjustments (+/−)')] as $field => $label)
                                        <div class="space-y-1">
                                            <label for="vat-{{ $field }}" class="font-medium">{{ $label }}</label>
                                            <x-filament::input.wrapper suffix="€">
                                                <x-filament::input id="vat-{{ $field }}" type="text" inputmode="decimal" wire:model.blur="{{ $field }}" required />
                                            </x-filament::input.wrapper>
                                            @error($field) <p class="text-danger-600" role="alert">{{ $message }}</p> @enderror
                                        </div>
                                    @endforeach
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Use a positive adjustment to increase the amount payable and a negative adjustment to reduce it. Include any prior credit used, if applicable.') }}</p>
                                    @foreach (['dueOn' => __('Confirmed deadline'), 'paidOn' => __('Date paid')] as $field => $label)
                                        <div class="space-y-1">
                                            <label for="vat-{{ $field }}" class="font-medium">{{ $label }}</label>
                                            <x-filament::input.wrapper>
                                                <x-filament::input id="vat-{{ $field }}" type="date" wire:model="{{ $field }}" />
                                            </x-filament::input.wrapper>
                                            @error($field) <p class="text-danger-600" role="alert">{{ $message }}</p> @enderror
                                        </div>
                                    @endforeach
                                </div>
                            </details>

                            <div class="space-y-1">
                                <label for="vat-reference" class="font-medium">{{ __('Reference') }}</label>
                                <x-filament::input.wrapper>
                                    <x-filament::input id="vat-reference" type="text" wire:model="reference" maxlength="500" required />
                                </x-filament::input.wrapper>
                                @error('reference') <p class="text-danger-600" role="alert">{{ $message }}</p> @enderror
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <x-filament::button type="submit" wire:loading.attr="disabled">{{ __('Confirm assessment') }}</x-filament::button>
                                <x-filament::button type="button" color="gray" wire:click="reloadReview" wire:loading.attr="disabled">{{ __('Refresh data') }}</x-filament::button>
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('If a document changes later, the assessment asks for confirmation again.') }}</p>
                        </form>
                    @endif
                </div>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
