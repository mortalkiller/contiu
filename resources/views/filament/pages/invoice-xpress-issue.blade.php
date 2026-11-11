@php
    use App\Enums\PortugueseVatRegion;
    use App\Filament\Pages\FiscalDocumentDraftsPage;
    use App\Filament\Pages\IntegrationsPage;
    use App\Filament\Pages\InvoiceXpressNoteIssuePage;
    use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
    use App\Models\InvoiceXpressIssueAttempt;
    use App\Support\InvoiceXpressIssue;
    use App\Support\InvoiceXpressIssueTerms;
    use App\Support\InvoiceXpressIssueAdvance;
    use App\Support\Money;

    $documentTypeLabel = fn (string $type): string => match ($type) {
        'invoice' => __('Invoice'),
        'invoice_receipt' => __('Invoice receipt'),
        'simplified_invoice' => __('Simplified invoice'),
        default => __('Document'),
    };
    $withholdingRateLabel = fn (int $rate): string => \Illuminate\Support\Number::format($rate / 100, maxPrecision: 2, locale: app()->getLocale()).'%';
    $vatCategories = ['reduced' => __('Reduced rate'), 'intermediate' => __('Intermediate rate'), 'standard' => __('Standard rate')];
    $approvedItemOptions = array_intersect_key($catalogChoices['items'] ?? [], array_flip($options['itemIds'] ?? []));
    $approvedItemPrices = array_intersect_key($catalogChoices['itemPrices'] ?? [], $approvedItemOptions);
    $additionalLineLimit = InvoiceXpressIssue::MaxLines - 1;
    $lineLabel = __('Line :number', ['number' => '{number}']);
@endphp

<x-filament-panels::page>
    @if ($savedDraft !== null)
        <div class="flex flex-wrap items-center gap-3" role="status">
            <span>{{ __('Draft #:id', ['id' => $savedDraft->id]) }} · {{ FiscalDocumentDraftsPage::statusLabel($savedDraft) }}</span>
            <x-filament::link :href="FiscalDocumentDraftsPage::getUrl()">{{ __('Drafts') }}</x-filament::link>
        </div>
    @endif

    @if ($readOnlyDraft)
        <x-filament::section :heading="__('Draft details')">
            <dl class="space-y-3 text-sm">
                @if ($savedDraft->remote_id !== null)
                    <div><dt>{{ __('InvoiceXpress draft ID') }}</dt><dd class="font-semibold">#{{ $savedDraft->remote_id }}</dd></div>
                @endif
                @if (isset($savedDraft->preview['domesticVat']))
                    @php($draftVat = $savedDraft->preview['domesticVat'])
                    <div><dt>{{ __('VAT region') }}</dt><dd class="font-semibold">{{ PortugueseVatRegion::from($draftVat['region'])->label() }} · {{ $vatCategories[$draftVat['category']] }} · {{ intdiv($savedDraft->preview['tax']['basisPoints'], 100) }}%</dd></div>
                @endif
                @php($total = $savedDraft->preview['totals']['total'] ?? null)
                @if (is_int($total))
                    <div><dt>{{ __('Document total') }}</dt><dd class="font-semibold">{{ Money::format($total, app()->getLocale()) }}</dd></div>
                @endif
            </dl>
        </x-filament::section>
    @else
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
        @if ($options === null)
            <x-filament::link :href="IntegrationsPage::getUrl()">{{ __('Integrations') }}</x-filament::link>
        @endif
    </div>
    @error('sequence') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
    @error('catalog') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
    @if (! $hasConnection)
        <p class="text-sm">{{ __('Restore the original InvoiceXpress connection to resume a pending attempt.') }}</p>
    @elseif ($options === null)
        <p class="text-sm">{{ __('Configure the approved items and series in Integrations.') }}</p>
    @endif

    @if ($options !== null)
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
                        <x-filament::input id="ix-operation-on" type="date" wire:model="operationOn" :max="today()->toDateString()" required />
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
            @if ($vatTreatment === 'domestic' && $exemptionReason === '')
                @php($selectedVatRegion = PortugueseVatRegion::tryFrom($vatRegion))
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="ix-vat-region" class="text-sm font-medium">{{ __('VAT region') }}</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="ix-vat-region" wire:model.live="vatRegion">
                                <option value="">{{ __('Choose a VAT region.') }}</option>
                                @foreach (PortugueseVatRegion::cases() as $region)
                                    <option value="{{ $region->value }}">{{ $region->label() }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                        @error('vatRegion') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div class="space-y-1">
                        <label for="ix-vat-category" class="text-sm font-medium">{{ __('VAT rate category') }}</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="ix-vat-category" wire:model.live="vatCategory" :disabled="$selectedVatRegion === null">
                                <option value="">{{ __('Choose a VAT rate category.') }}</option>
                                @foreach ($selectedVatRegion?->rates() ?? [] as $category => $basisPoints)
                                    <option value="{{ $category }}">{{ $vatCategories[$category] }} · {{ intdiv($basisPoints, 100) }}%</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                        @error('vatCategory') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    </div>
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
                            <x-filament::input id="ix-due-on" type="date" wire:model="dueOn" :min="today()->toDateString()" required />
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
                        <x-filament::input.select id="ix-client" wire:model="customerId" required>
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
                        <x-filament::input.select id="ix-item" wire:model="itemId" required>
                            <option value="">{{ __('Select item') }}</option>
                            @foreach ($approvedItemOptions as $id => $name)
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
                        <x-filament::input id="ix-quantity" type="number" wire:model="quantity" min="1" max="1000" step="1" required />
                    </x-filament::input.wrapper>
                    @error('quantity') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="space-y-1">
                    <label for="ix-discount" class="text-sm font-medium">{{ __('Discount (%)') }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input id="ix-discount" type="text" inputmode="decimal" wire:model="discount" />
                    </x-filament::input.wrapper>
                    @error('discount') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>
            <div
                wire:key="ix-line-editor-{{ md5(serialize($approvedItemOptions)) }}-{{ (int) ($catalogChoices !== null) }}"
                x-data="{
                    addLine() {
                        if (this.$wire.additionalLines.length >= {{ $additionalLineLimit }}) return
                        this.$wire.additionalLines = [...this.$wire.additionalLines, { itemId: '', quantity: '1', discount: '' }]
                    },
                    removeLine(index) {
                        this.$wire.additionalLines = this.$wire.additionalLines.filter((line, position) => position !== index)
                    },
                }"
                class="space-y-4"
            >
                <template x-for="(line, index) in $wire.additionalLines" :key="index">
                    <fieldset class="grid gap-3 sm:grid-cols-4">
                        <legend class="text-sm font-medium" x-text="@js($lineLabel).replace('{number}', index + 2)"></legend>
                        <div class="space-y-1">
                            <label :for="'ix-item-' + index" class="text-sm font-medium">{{ __('Approved item') }}</label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select x-bind:id="'ix-item-' + index" x-model="line.itemId" required>
                                    <option value="">{{ __('Select item') }}</option>
                                    @foreach ($approvedItemOptions as $id => $name)
                                        <option value="{{ $id }}">{{ $name }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>
                        <div class="space-y-1">
                            <label :for="'ix-quantity-' + index" class="text-sm font-medium">{{ __('Quantity') }}</label>
                            <x-filament::input.wrapper>
                                <x-filament::input x-bind:id="'ix-quantity-' + index" x-model="line.quantity" type="number" min="1" max="1000" step="1" required />
                            </x-filament::input.wrapper>
                        </div>
                        <div class="space-y-1">
                            <label :for="'ix-discount-' + index" class="text-sm font-medium">{{ __('Discount (%)') }}</label>
                            <x-filament::input.wrapper>
                                <x-filament::input x-bind:id="'ix-discount-' + index" x-model="line.discount" type="text" inputmode="decimal" />
                            </x-filament::input.wrapper>
                        </div>
                        <div class="self-end">
                            <x-filament::button type="button" color="gray" x-on:click="removeLine(index)">{{ __('Remove line') }}</x-filament::button>
                        </div>
                    </fieldset>
                </template>

                @foreach ($errors->get('additionalLines.*') as $field => $messages)
                    @foreach ($messages as $message)
                        <p class="text-sm text-danger-600" role="alert">{{ __('Line :number', ['number' => (int) explode('.', $field)[1] + 2]) }} · {{ $message }}</p>
                    @endforeach
                @endforeach
                @error('additionalLines') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror

                @if ($approvedItemPrices !== [])
                    <p
                        wire:key="ix-local-subtotal-{{ md5(serialize($approvedItemPrices)) }}"
                        class="text-sm font-medium"
                        x-load
                        x-load-src="{{ Illuminate\Support\Facades\Vite::asset('resources/js/finance-preview.js') }}"
                        x-data="financePreview({
                            read: (field) => $wire.$get(field),
                            locale: @js(App\Enums\Language::resolve(app()->getLocale())->value),
                            mode: 'invoiceSubtotal',
                            messages: @js(['subtotal' => __('Estimated subtotal before VAT: :amount')]),
                            prices: @js($approvedItemPrices),
                        })"
                        x-show="text !== ''"
                        x-text="text"
                        x-cloak
                        aria-live="polite"
                    ></p>
                @endif

                @error('discountBasisPoints') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                <div class="flex flex-wrap gap-2">
                    <x-filament::button
                        type="button"
                        color="gray"
                        :disabled="$catalogChoices === null"
                        x-bind:disabled="$wire.additionalLines.length >= {{ $additionalLineLimit }} || {{ $catalogChoices === null ? 'true' : 'false' }}"
                        x-on:click="addLine()"
                    >{{ __('Add line') }}</x-filament::button>
                    <x-filament::button type="submit" :disabled="$catalogChoices === null" wire:loading.attr="disabled">{{ __('Preview :document', ['document' => mb_strtolower($documentTypeLabel($documentType))]) }}</x-filament::button>
                </div>
            </div>
        </form>
    </x-filament::section>
    @endif

    @error('provider') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
    @error('confirmation') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
    @error('sequence') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror

    @error('advanceSettlement') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror

    @if ($previewData !== null)
    <div
        wire:key="ix-review-{{ md5(serialize($reviewedInputs)) }}"
        x-data="{
            reviewedInputs: @js($reviewedInputs),
            get reviewIsCurrent() {
                return Object.entries(this.reviewedInputs).every(([field, value]) =>
                    JSON.stringify(this.$wire[field]) === JSON.stringify(value))
            },
        }"
        x-show="reviewIsCurrent"
        x-bind:inert="!reviewIsCurrent"
    >
    @if (isset($previewData['advanceSettlement']))
        @php($settlement = $previewData['advanceSettlement'])
        <x-filament::section :heading="__('Preview advance settlement')">
            <div class="space-y-4 text-sm">
                <p>{{ $previewData['customer']['name'] }}@if ($previewData['customer']['nif'] !== null) · {{ __('Tax ID :number', ['number' => $previewData['customer']['nif']]) }}@endif</p>
                <dl class="space-y-3">
                    <div>
                        <dt class="font-semibold">{{ __('Invoice for the full service') }}</dt>
                        <dd>{{ __(':net + :tax VAT = :total', ['net' => Money::format($previewData['totals']['net'], app()->getLocale()), 'tax' => Money::format($previewData['totals']['tax'], app()->getLocale()), 'total' => Money::format($previewData['totals']['total'], app()->getLocale())]) }}</dd>
                        <ul>
                            @foreach ($previewData['lines'] as $line)
                                <li>{{ $line['item']['name'] }} · {{ $line['quantity'] }} × {{ Money::format($line['item']['unitCents'], app()->getLocale()) }}@isset($line['totals']['discount']) · {{ __('Discount :amount', ['amount' => Money::format($line['totals']['discount'], app()->getLocale())]) }}@endisset</li>
                            @endforeach
                        </ul>
                    </div>
                    <div>
                        <dt class="font-semibold">{{ __('Credit note for the advance') }}</dt>
                        <dd>{{ __('Original invoice :number dated :date', ['number' => $settlement['reference'], 'date' => $settlement['date']]) }}</dd>
                        <dd>{{ __(':net + :tax VAT = :total', ['net' => Money::format($settlement['creditNote']['totals']['net'], app()->getLocale()), 'tax' => Money::format($settlement['creditNote']['totals']['tax'], app()->getLocale()), 'total' => Money::format($settlement['creditNote']['totals']['total'], app()->getLocale())]) }}</dd>
                        @if ($settlement['received']['retained'] > 0)
                            <dd>{{ __(':cash received · :tax in income tax withheld on the advance', ['cash' => Money::format($settlement['received']['cash'], app()->getLocale()), 'tax' => Money::format($settlement['received']['retained'], app()->getLocale())]) }}</dd>
                        @endif
                    </div>
                    <div>
                        <dt class="font-semibold">{{ __('Balance after applying the credit') }}</dt>
                        <dd>{{ __(':net + :tax VAT = :total', ['net' => Money::format($settlement['balance']['net'], app()->getLocale()), 'tax' => Money::format($settlement['balance']['tax'], app()->getLocale()), 'total' => Money::format($settlement['balance']['total'], app()->getLocale())]) }}</dd>
                    </div>
                    @isset($settlement['remainingWithholding'])
                        <div>
                            <dt class="font-semibold">{{ __('Estimated withholding on the balance') }}</dt>
                            <dd>{{ __(':tax in income tax · :cash to receive', ['tax' => Money::format($settlement['remainingWithholding']['retained'], app()->getLocale()), 'cash' => Money::format($settlement['remainingWithholding']['cash'], app()->getLocale())]) }}</dd>
                        </div>
                    @endisset
                </dl>
                <p role="status">{{ __(InvoiceXpressIssueAdvance::ProviderBlock) }}</p>
                @if (isset($previewData['revision']))
                    <form wire:submit="saveDraft" class="space-y-2">
                        @isset($previewData['withholding'])
                            <p>{{ __('Withholding on the full invoice draft: :amount', ['amount' => Money::format($previewData['withholding']['retained'], app()->getLocale())]) }}</p>
                        @endisset
                        <x-filament::button type="submit" wire:loading.attr="disabled">{{ __('Save draft') }}</x-filament::button>
                    </form>
                @endif
                <div class="flex flex-wrap gap-3">
                    <x-filament::link :href="InvoiceXpressNoteIssuePage::getUrl()">{{ __('Credit and debit notes') }}</x-filament::link>
                    <x-filament::link :href="FiscalDocumentResource::getUrl()">{{ __('Tax documents') }}</x-filament::link>
                </div>
            </div>
        </x-filament::section>
    @else
        <x-filament::section :heading="__('Review draft')">
            <form wire:submit="saveDraft" class="space-y-4">
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
                        @elseif (isset($previewData['domesticVat']))
                            <dd>{{ PortugueseVatRegion::from($previewData['domesticVat']['region'])->label() }} · {{ $vatCategories[$previewData['domesticVat']['category']] }}</dd>
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
                <x-filament::button type="submit" wire:loading.attr="disabled">{{ __('Save draft') }}</x-filament::button>
            </form>
        </x-filament::section>
    @endif

    </div>
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
                </li>
            @empty
                <li class="text-sm text-gray-600 dark:text-gray-300">{{ __('No issuing attempts.') }}</li>
            @endforelse
        </ul>
    </x-filament::section>
    @endif
</x-filament-panels::page>
