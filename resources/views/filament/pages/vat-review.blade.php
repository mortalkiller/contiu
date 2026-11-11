@php
    use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
    use App\Support\Money;
    use App\Support\Period;

    $monthLabel = fn (int $month): string => app()->getLocale() === 'pt_PT'
        ? Period::MONTHS[$month - 1]
        : \Carbon\CarbonImmutable::create(2000, $month, 1)->locale(app()->getLocale())->translatedFormat('F');
    $balance = $review->confirmedBalance();
    $periodLabel = $months === 3
        ? __('Q:quarter :year', ['quarter' => $review->start->quarter, 'year' => $year])
        : __(':month, :year', ['month' => $monthLabel($month), 'year' => $year]);
@endphp

<x-filament-panels::page>
    @include('filament.pages.partials.tax-summary', ['stats' => $summary, 'notice' => $summaryNotice])

    <x-filament::section :heading="$periodLabel">
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="space-y-1">
                <label for="vat-year" class="text-sm font-medium">{{ __('Year') }}</label>
                <x-filament::input.wrapper>
                    <x-filament::input id="vat-year" type="number" min="1900" max="9998" wire:model.live.change="year" />
                </x-filament::input.wrapper>
            </div>
            <div class="space-y-1">
                <label for="vat-months" class="text-sm font-medium">{{ __('Frequency') }}</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="vat-months" wire:model.live="months">
                        <option value="1">{{ __('Monthly') }}</option>
                        <option value="3">{{ __('Quarterly') }}</option>
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
            <div class="space-y-1">
                <label for="vat-month" class="text-sm font-medium">{{ $months === 3 ? __('Quarter') : __('Month') }}</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="vat-month" wire:model.live="month">
                        @if ($months === 3)
                            @foreach ([1, 4, 7, 10] as $startMonth)
                                <option value="{{ $startMonth }}">{{ __('Q:quarter', ['quarter' => intdiv($startMonth - 1, 3) + 1]) }}</option>
                            @endforeach
                        @else
                            @foreach (range(1, 12) as $optionMonth)
                                <option value="{{ $optionMonth }}">{{ $monthLabel($optionMonth) }}</option>
                            @endforeach
                        @endif
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('VAT shown on documents')">
        <dl class="grid gap-4 sm:grid-cols-2">
            <div>
                <dt class="text-sm text-gray-600 dark:text-gray-300">{{ __('Issued documents') }}</dt>
                <dd class="text-lg font-semibold">{{ Money::format($review->issuedVat, app()->getLocale()) }}</dd>
            </div>
            <div>
                <dt class="text-sm text-gray-600 dark:text-gray-300">{{ __('Received documents') }}</dt>
                <dd class="text-lg font-semibold">{{ Money::format($review->receivedVat, app()->getLocale()) }}</dd>
            </div>
        </dl>
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
            @if ($review->documents->isEmpty())
                {{ __('No documents in this period.') }}
            @else
                {{ __('The total uses the VAT shown on invoices and invoice-receipts in EUR. When VAT becomes due, deductions and adjustments are checked in the assessment.') }}
            @endif
        </p>
    </x-filament::section>

    @foreach (['supported' => __('Included in the document total'), 'unresolved' => __('Needs review'), 'excluded' => __('Excluded from the document total')] as $status => $label)
        @php($rows = $review->documents->where('status', $status))
        @if ($rows->isNotEmpty())
            <x-filament::section :heading="$label">
                <ul class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($rows as ['document' => $document, 'reason' => $reason])
                        <li wire:key="vat-document-{{ $document->id }}" class="flex flex-wrap justify-between gap-x-4 gap-y-1 py-2 text-sm">
                            <div>
                                <a class="font-semibold underline" href="{{ FiscalDocumentResource::getUrl('index', ['tableSearch' => $document->number]) }}">{{ $document->number }}</a>
                                <span>{{ $document->type->getLabel() }} · {{ $document->direction->getLabel() }} · {{ $document->document_date->format(app()->getLocale() === 'pt_PT' ? 'd/m/Y' : 'm/d/Y') }}</span>
                            </div>
                            <span>{{ $reason }} @if ($document->currency === 'EUR') · {{ Money::format($document->vat_amount, app()->getLocale()) }} @endif</span>
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif
    @endforeach

    <x-filament::section :heading="__('Period assessment')">
        <div class="space-y-4">
            @if ($balance === null)
                <x-filament::badge color="warning">{{ $review->confirmation === null ? __('Pending confirmation') : __('Documents changed · confirmation needed again') }}</x-filament::badge>
            @else
                <x-filament::badge color="success">{{ __('Confirmed') }}</x-filament::badge>
                <p class="text-lg font-semibold">{{ $balance < 0 ? __('Credit balance') : ($balance === 0 ? __('Nothing to pay') : __('Payable')) }} · {{ Money::format(abs($balance), app()->getLocale()) }}</p>
                <p class="text-sm">
                    {{ $review->confirmation->reference }}
                    @if ($review->dueOn()) · {{ __('Stated deadline :date', ['date' => $review->dueOn()->format(app()->getLocale() === 'pt_PT' ? 'd/m/Y' : 'm/d/Y')]) }} @endif
                    @if ($review->isPaid()) · {{ __('Paid on :date', ['date' => $review->paidOn()->format(app()->getLocale() === 'pt_PT' ? 'd/m/Y' : 'm/d/Y')]) }} @endif
                </p>
                @if (! $this::canConfirm())
                    <dl class="grid gap-4 text-sm sm:grid-cols-3">
                        <div><dt>{{ __('Output VAT') }}</dt><dd class="font-semibold">{{ Money::format($review->confirmation->liquidated, app()->getLocale()) }}</dd></div>
                        <div><dt>{{ __('Deductible VAT') }}</dt><dd class="font-semibold">{{ Money::format($review->confirmation->deductible, app()->getLocale()) }}</dd></div>
                        <div><dt>{{ __('Net adjustments') }}</dt><dd class="font-semibold">{{ Money::format($review->confirmation->adjustments, app()->getLocale()) }}</dd></div>
                    </dl>
                @endif
            @endif

            @if ($this::canConfirm())
                <form wire:submit="confirm" class="space-y-4">
                    <div class="grid gap-4 sm:grid-cols-3">
                        @foreach (['liquidated' => __('Output VAT'), 'deductible' => __('Deductible VAT'), 'adjustments' => __('Net adjustments (+/−)')] as $field => $label)
                            <div class="space-y-1">
                                <label for="vat-{{ $field }}" class="text-sm font-medium">{{ $label }}</label>
                                <x-filament::input.wrapper suffix="€">
                                    <x-filament::input id="vat-{{ $field }}" type="text" inputmode="decimal" wire:model="{{ $field }}" required />
                                </x-filament::input.wrapper>
                                @error($field) <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                            </div>
                        @endforeach
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('Use a positive adjustment to increase the amount payable and a negative adjustment to reduce it. Include any prior credit used, if applicable.') }}</p>
                    <div class="space-y-1">
                        <label for="vat-reference" class="text-sm font-medium">{{ __('Reviewed assessment reference') }}</label>
                        <x-filament::input.wrapper>
                            <x-filament::input id="vat-reference" type="text" wire:model="reference" maxlength="500" required />
                        </x-filament::input.wrapper>
                        @error('reference') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach (['dueOn' => __('Confirmed deadline'), 'paidOn' => __('Date paid')] as $field => $label)
                            <div class="space-y-1">
                                <label for="vat-{{ $field }}" class="text-sm font-medium">{{ $label }}</label>
                                <x-filament::input.wrapper>
                                    <x-filament::input id="vat-{{ $field }}" type="date" wire:model="{{ $field }}" />
                                </x-filament::input.wrapper>
                                @error($field) <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                            </div>
                        @endforeach
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-filament::button type="submit" wire:loading.attr="disabled">{{ __('Confirm assessment') }}</x-filament::button>
                        <x-filament::button type="button" color="gray" wire:click="reloadReview" wire:loading.attr="disabled">{{ __('Refresh data') }}</x-filament::button>
                    </div>
                </form>
            @endif
        </div>
    </x-filament::section>
</x-filament-panels::page>
