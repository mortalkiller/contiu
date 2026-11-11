@php
    use App\Filament\Pages\InvoiceXpressImport;
    use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
    use App\Models\InvoiceXpressIssueAttempt;
    use App\Support\InvoiceXpressIssueTerms;
    use App\Support\Money;
    use ToneGabes\Filament\Icons\Enums\Phosphor;

    use function Filament\Support\generate_icon_html;

    $documentTypeLabel = fn (string $type): string => match ($type) {
        'invoice' => __('Invoice'),
        'invoice_receipt' => __('Invoice receipt'),
        'simplified_invoice' => __('Simplified invoice'),
        default => __('Document'),
    };
    $withholdingRateLabel = fn (int $rate): string => \Illuminate\Support\Number::format($rate / 100, maxPrecision: 2, locale: app()->getLocale()).'%';
@endphp

<x-filament-panels::page>
    <div class="flex flex-wrap items-center gap-3">
        @if ($hasConnection && $options !== null)
            <x-filament::button type="button" color="gray" wire:click="loadCatalog" wire:loading.attr="disabled">
                {{ $catalogChoices === null ? __('Look up InvoiceXpress catalogue') : __('Refresh catalogue') }}
            </x-filament::button>
            @if ($draftOnly)
                <span class="text-sm">{{ __('INVOICEXPRESSDEMO series · Drafts only') }}</span>
            @elseif (isset($catalogChoices['sequences'][$options['sequenceId']]))
                <span class="text-sm">{{ __('Series :series', ['series' => $catalogChoices['sequences'][$options['sequenceId']]]) }}</span>
            @endif
        @endif
        <x-filament::link :href="\App\Filament\Pages\InvoiceXpressSettingsPage::getUrl()">
            {{ __('Issuing settings') }}
        </x-filament::link>
    </div>
    @error('sequence') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
    @error('catalog') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
    @if (! $hasConnection)
        <p class="text-sm">{{ __('Restore the original InvoiceXpress connection to resume a pending attempt.') }}</p>
    @elseif (! $canWriteDocuments)
        <p class="text-sm">{{ __('Issuing is pending confirmation that the API preserves the InvoiceXpress catalogue.') }}</p>
    @endif

    @if ($createdDraftId !== null)
        <p class="text-sm" role="status">{{ __('Draft #:id created in InvoiceXpress.', ['id' => $createdDraftId]) }}</p>
    @endif

    @if ($options !== null)
    @unless ($draftOnly)
    <section class="contiu-steps fap-edge-to-edge" aria-label="{{ __('Issuing steps') }}">
        <ol class="contiu-steps-list" style="--contiu-steps: {{ count($steps) }}">
            @foreach ($steps as $index => $step)
                <li>
                    <div class="contiu-step" data-done="{{ $step['done'] ? 'true' : 'false' }}">
                        <span class="contiu-step-title">{{ $index + 1 }}. {{ $step['label'] }}</span>
                        <span class="contiu-step-state">
                            {{ generate_icon_html($step['done'] ? Phosphor::CheckCircle : Phosphor::Clock) }}
                            {{ $step['state'] }}
                        </span>
                    </div>
                </li>
            @endforeach
        </ol>
    </section>
    @endunless

    <x-filament::section :heading="__('Document details')">
        <form wire:submit="preview" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-1">
                    <label for="ix-billing-source" class="text-sm font-medium">{{ __('Invoice source') }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="ix-billing-source" wire:model.live="billingSource">
                            <option value="receivable">{{ __('Existing receivable') }}</option>
                            <option value="direct">{{ __('Direct invoicing') }}</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    @error('billingSource') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="space-y-1">
                    <label for="ix-vat-treatment" class="text-sm font-medium">{{ __('VAT treatment') }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="ix-vat-treatment" wire:model.live="vatTreatment">
                            <option value="domestic">{{ __('Domestic transaction') }}</option>
                            <option value="euService">{{ __('Service to an EU business') }}</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    @error('vatTreatment') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="space-y-1">
                <label for="ix-document-type" class="text-sm font-medium">{{ __('Document type') }}</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="ix-document-type" wire:model.live="documentType" :disabled="(! $draftOnly && $billingSource === 'direct') || $vatTreatment === 'euService'">
                        @foreach (array_keys($documentTypes) as $type)
                            <option value="{{ $type }}">{{ $documentTypeLabel($type) }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                @error('documentType') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                @error('payment') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
            </div>
            @if ($vatTreatment === 'euService')
                <div class="space-y-1">
                    <label for="ix-operation-on" class="text-sm font-medium">{{ __('Service date') }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input id="ix-operation-on" type="date" wire:model.live="operationOn" :max="today()->toDateString()" required />
                    </x-filament::input.wrapper>
                    @error('operationOn') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    @error('euOperationOn') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    @error('exemptionReason') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                </div>
            @else
            <div class="space-y-1">
                <label for="ix-exemption-reason" class="text-sm font-medium">{{ __('Reason for VAT exemption') }}</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="ix-exemption-reason" wire:model.live="exemptionReason">
                        <option value="">{{ __('No exemption') }}</option>
                        <option value="M10">{{ __('Article 53 VAT exemption (M10)') }}</option>
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                @error('exemptionReason') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
            </div>
            @endif
            @if ($vatTreatment === 'domestic' && $documentType !== 'simplified_invoice')
                <div class="space-y-1">
                    <label for="ix-withholding-rate" class="text-sm font-medium">{{ __('Confirmed withholding tax rate') }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="ix-withholding-rate" wire:model.live="withholdingRate">
                            <option value="">{{ __('No withholding tax') }}</option>
                            @foreach (array_keys(InvoiceXpressIssueTerms::WithholdingRates) as $rate)
                                <option value="{{ $rate }}">{{ $withholdingRateLabel($rate) }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    @error('withholdingRate') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                </div>
            @endif
            <div class="grid gap-4 sm:grid-cols-2">
                @if ($billingSource === 'direct')
                    <div class="space-y-1">
                        <label for="ix-local-client" class="text-sm font-medium">{{ __('Client in Contiu') }}</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="ix-local-client" wire:model.live="localClientId" required>
                                <option value="">{{ __('Select client') }}</option>
                                @foreach ($localClients as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                        @error('localClientId') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div class="space-y-1">
                        <label for="ix-due-on" class="text-sm font-medium">{{ __('Due date') }}</label>
                        <x-filament::input.wrapper>
                            <x-filament::input id="ix-due-on" type="date" wire:model.live="dueOn" :min="today()->toDateString()" required />
                        </x-filament::input.wrapper>
                        @error('dueOn') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                        @error('direct.dueOn') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    </div>
                @else
                <div class="space-y-1">
                    <label for="ix-receivable" class="text-sm font-medium">{{ __('Receivable') }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="ix-receivable" wire:model.live="receivableId" required>
                            <option value="">{{ __('Select receivable') }}</option>
                            @foreach ($receivables as $receivable)
                                <option value="{{ $receivable->id }}">{{ $receivable->label }} · {{ $receivable->client?->name }} · {{ Money::format($receivable->amount, app()->getLocale()) }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    @error('receivableId') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    @error('receivable') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                </div>
                @endif
                <div class="space-y-1">
                    <label for="ix-client" class="text-sm font-medium">{{ __('Existing client in InvoiceXpress') }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="ix-client" wire:model.live="customerId" required>
                            <option value="">{{ __('Select client') }}</option>
                            @foreach ($catalogChoices['clients'] ?? [] as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    @error('customerId') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    @error('customer') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="space-y-1">
                    <label for="ix-item" class="text-sm font-medium">{{ __('Approved item') }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="ix-item" wire:model.live="itemId" required>
                            <option value="">{{ __('Select item') }}</option>
                            @foreach (array_intersect_key($catalogChoices['items'] ?? [], array_flip($options['itemIds'])) as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    @error('itemId') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    @error('item') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    @error('tax') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="space-y-1">
                    <label for="ix-quantity" class="text-sm font-medium">{{ __('Quantity') }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input id="ix-quantity" type="number" wire:model.live.change="quantity" min="1" max="1000" step="1" required />
                    </x-filament::input.wrapper>
                    @error('quantity') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="space-y-1">
                    <label for="ix-discount" class="text-sm font-medium">{{ __('Discount (%)') }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input id="ix-discount" type="text" inputmode="decimal" wire:model.live.change="discount" />
                    </x-filament::input.wrapper>
                    @error('discount') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>
            @foreach ($additionalLines as $index => $line)
                <fieldset wire:key="ix-line-{{ $index }}" class="grid gap-3 sm:grid-cols-4">
                    <legend class="text-sm font-medium">{{ __('Line :number', ['number' => $loop->iteration + 1]) }}</legend>
                    <div class="space-y-1">
                        <label for="ix-item-{{ $index }}" class="text-sm font-medium">{{ __('Approved item') }}</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="ix-item-{{ $index }}" wire:model.live="additionalLines.{{ $index }}.itemId" required>
                                <option value="">{{ __('Select item') }}</option>
                                @foreach (array_intersect_key($catalogChoices['items'] ?? [], array_flip($options['itemIds'])) as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                        @error("additionalLines.$index.itemId") <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div class="space-y-1">
                        <label for="ix-quantity-{{ $index }}" class="text-sm font-medium">{{ __('Quantity') }}</label>
                        <x-filament::input.wrapper>
                            <x-filament::input id="ix-quantity-{{ $index }}" type="number" wire:model.live.change="additionalLines.{{ $index }}.quantity" min="1" max="1000" step="1" required />
                        </x-filament::input.wrapper>
                        @error("additionalLines.$index.quantity") <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div class="space-y-1">
                        <label for="ix-discount-{{ $index }}" class="text-sm font-medium">{{ __('Discount (%)') }}</label>
                        <x-filament::input.wrapper>
                            <x-filament::input id="ix-discount-{{ $index }}" type="text" inputmode="decimal" wire:model.live.change="additionalLines.{{ $index }}.discount" />
                        </x-filament::input.wrapper>
                        @error("additionalLines.$index.discount") <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div class="self-end">
                        <x-filament::button type="button" color="gray" wire:click="removeLine({{ (int) $index }})" wire:loading.attr="disabled">{{ __('Remove line') }}</x-filament::button>
                    </div>
                </fieldset>
            @endforeach
            @error('additionalLines') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
            @error('discountBasisPoints') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
            <div class="flex flex-wrap gap-2">
                <x-filament::button type="button" color="gray" :disabled="$catalogChoices === null || count($additionalLines) >= \App\Support\InvoiceXpressIssue::MaxLines - 1" wire:click="addLine" wire:loading.attr="disabled">{{ __('Add line') }}</x-filament::button>
                <x-filament::button type="submit" :disabled="$catalogChoices === null" wire:loading.attr="disabled">{{ __('Preview :document', ['document' => mb_strtolower($documentTypeLabel($documentType))]) }}</x-filament::button>
            </div>
        </form>
    </x-filament::section>
    @endif

    @error('provider') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
    @error('confirmation') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
    @error('sequence') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror

    @error('advanceSettlement') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror

    @if (isset($previewData['advanceSettlement']))
        @php($settlement = $previewData['advanceSettlement'])
        <x-filament::section :heading="__('Preview advance settlement')">
            <div class="space-y-4 text-sm">
                <p>{{ $previewData['customer']['name'] }}@if ($previewData['customer']['nif'] !== null) · {{ __('Tax ID :number', ['number' => $previewData['customer']['nif']]) }}@endif</p>
                <dl class="space-y-3">
                    <div>
                        <dt class="font-semibold">{{ __('Full service amount') }}</dt>
                        <dd>{{ __(':net + :tax VAT = :total', ['net' => Money::format($previewData['totals']['net'], app()->getLocale()), 'tax' => Money::format($previewData['totals']['tax'], app()->getLocale()), 'total' => Money::format($previewData['totals']['total'], app()->getLocale())]) }}</dd>
                        <ul>
                            @foreach ($previewData['lines'] as $line)
                                <li>{{ $line['item']['name'] }} · {{ $line['quantity'] }} × {{ Money::format($line['item']['unitCents'], app()->getLocale()) }}@isset($line['totals']['discount']) · {{ __('Discount :amount', ['amount' => Money::format($line['totals']['discount'], app()->getLocale())]) }}@endisset</li>
                            @endforeach
                        </ul>
                    </div>
                    <div>
                        <dt class="font-semibold">{{ __('Advance deduction') }}</dt>
                        <dd>{{ __('Original invoice :number dated :date', ['number' => $settlement['reference'], 'date' => $settlement['date']]) }}</dd>
                        <dd>{{ __('−:net net and −:tax VAT · −:total in total', ['net' => Money::format($settlement['deduction']['net'], app()->getLocale()), 'tax' => Money::format($settlement['deduction']['tax'], app()->getLocale()), 'total' => Money::format($settlement['deduction']['total'], app()->getLocale())]) }}</dd>
                        @isset($settlement['received'])
                            <dd>{{ __(':cash received · :tax in income tax withheld on the advance', ['cash' => Money::format($settlement['received']['cash'], app()->getLocale()), 'tax' => Money::format($settlement['received']['retained'], app()->getLocale())]) }}</dd>
                        @endisset
                    </div>
                    <div>
                        <dt class="font-semibold">{{ __('Final invoice total') }}</dt>
                        <dd>{{ __(':net + :tax VAT = :total', ['net' => Money::format($settlement['balance']['net'], app()->getLocale()), 'tax' => Money::format($settlement['balance']['tax'], app()->getLocale()), 'total' => Money::format($settlement['balance']['total'], app()->getLocale())]) }}</dd>
                    </div>
                    @isset($previewData['withholding'])
                        <div>
                            <dt class="font-semibold">{{ __('Expected withholding tax on the final payment') }}</dt>
                            <dd>{{ __(':tax in income tax · :cash to receive', ['tax' => Money::format($previewData['withholding']['retained'], app()->getLocale()), 'cash' => Money::format($previewData['withholding']['cash'], app()->getLocale())]) }}</dd>
                        </div>
                    @endisset
                </dl>
                <p role="status">{{ __('Issuing the settlement via the API is blocked until InvoiceXpress confirms how to deduct the advance and reference the original invoice without changing the catalogue.') }}</p>
                <p>{{ __('After issuing through a method confirmed with the provider, check the deduction and reference on the final invoice. Import or record the invoice, link it to the same charge and use “Link advance”.') }}</p>
                <div class="flex flex-wrap gap-3">
                    <x-filament::link :href="InvoiceXpressImport::getUrl()">{{ __('Import final invoice') }}</x-filament::link>
                    <x-filament::link :href="FiscalDocumentResource::getUrl()">{{ __('Tax documents') }}</x-filament::link>
                </div>
            </div>
        </x-filament::section>
    @elseif ($previewData !== null)
        @unless ($draftOnly)
        @include('filament.pages.partials.tax-summary', ['stats' => [], 'notice' => [
            'title' => __('Once issued, an invoice can only be corrected with a credit note.'),
            'body' => __('Check the client, lines and totals before issuing.'),
            'tone' => 'warning',
        ]])
        @endunless

        <x-filament::section :heading="$draftOnly ? __('Confirm draft') : __('Confirm issuing')">
            <form wire:submit="{{ $draftOnly ? 'createDraft' : 'confirm' }}" class="space-y-4">
                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt>{{ __('Client in InvoiceXpress') }}</dt>
                        <dd class="font-semibold">{{ $previewData['customer']['name'] }}@if ($previewData['customer']['nif'] !== null) · {{ __('Tax ID :number', ['number' => $previewData['customer']['nif']]) }}@endif</dd>
                    </div>
                    <div>
                        <dt>{{ __('Issue and due dates') }}</dt>
                        <dd class="font-semibold">{{ $previewData['invoice']['date'] }} · {{ $previewData['invoice']['due_date'] }}</dd>
                    </div>
                    <div>
                        <dt>{{ __('Document type') }}</dt>
                        <dd class="font-semibold">{{ $documentTypeLabel($previewData['documentType'] ?? 'invoice') }}</dd>
                    </div>
                    @isset($previewData['payment'])
                        <div>
                            <dt>{{ __('Recorded payment') }}</dt>
                            <dd class="font-semibold">#{{ $previewData['payment']['id'] }} · {{ $previewData['invoice']['date'] }} · {{ Money::format($previewData['payment']['amount'], app()->getLocale()) }}</dd>
                        </div>
                    @endisset
                    <div>
                        <dt>{{ __('Series and internal reference') }}</dt>
                        <dd class="font-semibold">{{ $previewData['sequence']['name'] }} · {{ $previewData['invoice']['reference'] }}</dd>
                    </div>
                    <div>
                        <dt>{{ __('Net amount and VAT') }}</dt>
                        <dd class="font-semibold">{{ Money::format($previewData['totals']['net'], app()->getLocale()) }} + {{ Money::format($previewData['totals']['tax'], app()->getLocale()) }} · {{ $previewData['tax']['name'] }} ({{ intdiv($previewData['tax']['basisPoints'], 100) }}%)</dd>
                        @if (isset($previewData['euService']))
                            <dd>{{ __('VAT reverse charge (M40) · Service on :date', ['date' => \Carbon\CarbonImmutable::parse($previewData['euOperationOn'])->format(app()->getLocale() === 'pt_PT' ? 'd/m/Y' : 'm/d/Y')]) }}</dd>
                        @elseif (isset($previewData['invoice']['tax_exemption_reason']))
                            <dd>{{ __('Article 53 VAT exemption (M10)') }}</dd>
                        @endif
                    </div>
                    <div>
                        <dt>{{ isset($previewData['withholding']) ? __('Gross total') : __('Document total') }}</dt>
                        <dd class="font-semibold">{{ Money::format($previewData['totals']['total'], app()->getLocale()) }}</dd>
                        @isset($previewData['totals']['discount'])
                            <dd>{{ __('Total discount :amount', ['amount' => Money::format($previewData['totals']['discount'], app()->getLocale())]) }}</dd>
                        @endisset
                    </div>
                    @isset($previewData['withholding'])
                        <div>
                            <dt>{{ isset($previewData['payment']) ? __('Tax withheld') : __('Expected withholding tax on payment') }}</dt>
                            <dd class="font-semibold">{{ Money::format($previewData['withholding']['retained'], app()->getLocale()) }} · {{ $withholdingRateLabel($previewData['withholding']['basisPoints']) }}</dd>
                        </div>
                        <div>
                            <dt>{{ isset($previewData['payment']) ? __('Net amount received') : __('Expected net amount to receive') }}</dt>
                            <dd class="font-semibold">{{ Money::format($previewData['withholding']['cash'], app()->getLocale()) }}</dd>
                        </div>
                    @endisset
                </dl>
                <ul class="divide-y divide-gray-200 text-sm dark:divide-white/10">
                    @foreach ($previewData['lines'] ?? [['item' => $previewData['item'], 'quantity' => $previewData['invoice']['items'][0]['quantity'], 'totals' => $previewData['totals']]] as $line)
                        <li class="py-2">
                            <span class="font-semibold">{{ $line['item']['name'] }}</span> · {{ $line['quantity'] }} × {{ Money::format($line['item']['unitCents'], app()->getLocale()) }} · {{ __(':net + :tax VAT = :total', ['net' => Money::format($line['totals']['net'], app()->getLocale()), 'tax' => Money::format($line['totals']['tax'], app()->getLocale()), 'total' => Money::format($line['totals']['total'], app()->getLocale())]) }}
                            @isset($line['totals']['discount']) <p>{{ __('Discount :amount', ['amount' => Money::format($line['totals']['discount'], app()->getLocale())]) }}</p> @endisset
                            @if ($line['item']['description'] !== '') <p>{{ $line['item']['description'] }}</p> @endif
                        </li>
                    @endforeach
                </ul>
                <label for="ix-accept" class="flex items-center gap-2 text-sm">
                    <x-filament::input.checkbox id="ix-accept" wire:model="accepted" />
                    {{ $draftOnly ? __('I have checked the draft details.') : __('I have checked this information and authorise issuing this document in InvoiceXpress.') }}
                </label>
                @error('accepted') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                @if (! $draftOnly && isset($previewData['withholding']) && ! $canWriteWithholding)
                    <p role="status">{{ __('Issuing with withholding tax is pending validation in the InvoiceXpress sandbox.') }}</p>
                @endif
                @if ($draftOnly)
                    <x-filament::button type="submit" wire:loading.attr="disabled">{{ __('Create draft') }}</x-filament::button>
                @else
                    <x-filament::button type="submit" :disabled="! $canWriteDocuments || (isset($previewData['withholding']) && ! $canWriteWithholding)" wire:loading.attr="disabled">{{ __('Confirm and issue') }}</x-filament::button>
                @endif
            </form>
        </x-filament::section>
    @endif

    <x-filament::section :heading="__('Issuing attempts')">
        <ul class="divide-y divide-gray-200 dark:divide-white/10">
            @forelse ($attempts as $attempt)
                <li wire:key="ix-attempt-{{ $attempt->id }}" class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                    <div>
                        <span class="font-semibold">{{ __('Attempt #:id', ['id' => $attempt->id]) }}</span>
                        <span> · {{ $attempt->created_at->format(app()->getLocale() === 'pt_PT' ? 'd/m/Y H:i' : 'm/d/Y H:i') }}</span>
                        <span> · {{ $documentTypeLabel($attempt->snapshot['documentType'] ?? 'invoice') }} · {{ isset($attempt->snapshot['direct']) ? __('Direct invoicing') : __('Receivable #:id', ['id' => $attempt->snapshot['receivableId']]) }}</span>
                        @if ($attempt->state === InvoiceXpressIssueAttempt::Finalized)
                            <p>{{ $attempt->document_reference }} · ID {{ $attempt->provider_invoice_id }} · ATCUD {{ $attempt->atcud }}</p>
                        @elseif ($attempt->state === InvoiceXpressIssueAttempt::Blocked)
                            <p>{{ __('Manual review required in InvoiceXpress.') }}</p>
                        @else
                            <p>{{ __('Pending confirmation in InvoiceXpress.') }}</p>
                        @endif
                    </div>
                    @if (in_array($attempt->state, InvoiceXpressIssueAttempt::Recoverable, true))
                        <x-filament::button type="button" color="gray" wire:click="recover({{ $attempt->id }})" wire:loading.attr="disabled">
                            {{ __('Check and resume attempt') }}
                        </x-filament::button>
                    @endif
                </li>
            @empty
                <li class="text-sm text-gray-600 dark:text-gray-300">{{ __('No issuing attempts.') }}</li>
            @endforelse
        </ul>
    </x-filament::section>
</x-filament-panels::page>
